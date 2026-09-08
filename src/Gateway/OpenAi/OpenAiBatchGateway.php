<?php

namespace AiBatch\Gateway\OpenAi;

use AiBatch\BatchHandle;
use AiBatch\BatchRequestCounts;
use AiBatch\BatchRequestFailed;
use AiBatch\BatchRequestFailureType;
use AiBatch\BatchStatus;
use AiBatch\Contracts\BatchGateway;
use AiBatch\Exceptions\BatchException;
use AiBatch\Exceptions\BatchNotReadyException;
use AiBatch\Gateway\Concerns\BuildsAgentResponses;
use AiBatch\Gateway\Concerns\NarrowsProviders;
use AiBatch\Gateway\Concerns\ParsesJsonLines;
use AiBatch\Gateway\Concerns\ResolvesRequestContext;
use AiBatch\Requests\RequestContext;
use Carbon\CarbonImmutable;
use Generator;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Throwable;

/**
 * OpenAI Batch API: JSONL file upload + POST /v1/batches, results read back from the output file.
 *
 * Extends the SDK gateway so request bodies and result parsing go through the exact same
 * protected builders the synchronous Responses API path uses.
 */
class OpenAiBatchGateway extends OpenAiGateway implements BatchGateway
{
    use BuildsAgentResponses, NarrowsProviders, ParsesJsonLines, ResolvesRequestContext;

    public function textEndpoint(): string
    {
        return '/v1/responses';
    }

    public function resolveTextRequest(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
    ): array {
        $provider = $this->asProvider($provider);

        return $this->buildTextRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options);
    }

    public function submitBatch(TextProvider $provider, array $requests, array $options = []): BatchHandle
    {
        $provider = $this->asProvider($provider);

        $endpoint = $this->textEndpoint();

        foreach ($requests as $customId => $request) {
            if ($request->endpoint !== $endpoint) {
                throw new BatchException("Request [{$customId}] targets [{$request->endpoint}]; an OpenAI batch must use a single endpoint [{$endpoint}].");
            }
        }

        if (! $provider instanceof FileProvider) {
            throw new BatchException(sprintf('Provider [%s] cannot store files, which the OpenAI batch API requires.', $provider->name()));
        }

        $jsonl = $this->toJsonLines(
            (function () use ($requests): Generator {
                foreach ($requests as $customId => $request) {
                    yield $request->toBatchLine((string) $customId);
                }
            })(),
        );

        // Upload through the SDK's file provider so Files::fake(), the FileStored event and the
        // provider's own error handling apply to the batch input like any other file.
        $fileId = Ai::fakeableFileProvider($provider->name())->putFile(
            Document::fromString($jsonl, 'application/jsonl')
                ->as('batch.jsonl')
                ->withProviderOptions(['purpose' => 'batch']),
        )->id;

        $data = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)->post('batches', array_filter([
                'input_file_id' => $fileId,
                'endpoint' => $endpoint,
                'completion_window' => $options['completion_window'] ?? '24h',
                'metadata' => $options['metadata'] ?? null,
            ]))->json(),
        );

        return $this->toHandle($data, $provider);
    }

    public function retrieveBatch(TextProvider $provider, string $id): BatchHandle
    {
        $provider = $this->asProvider($provider);

        $data = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)->get("batches/{$id}")->json(),
        );

        return $this->toHandle($data, $provider);
    }

    public function cancelBatch(TextProvider $provider, string $id): BatchHandle
    {
        $provider = $this->asProvider($provider);

        $data = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)->post("batches/{$id}/cancel")->json(),
        );

        return $this->toHandle($data, $provider);
    }

    public function batchResults(TextProvider $provider, BatchHandle $batch, array $contexts = [], ?bool $structured = null): iterable
    {
        $provider = $this->asProvider($provider);

        if (! $batch->hasResults()) {
            $batch = $this->retrieveBatch($provider, $batch->id);
        }

        if (! $batch->hasResults()) {
            throw BatchNotReadyException::forBatch($batch);
        }

        foreach ([$batch->raw['output_file_id'] ?? null, $batch->raw['error_file_id'] ?? null] as $fileId) {
            if (! $fileId) {
                continue;
            }

            $response = $this->withErrorHandling(
                $provider->name(),
                fn () => $this->client($provider, 300)->withOptions(['stream' => true])->get("files/{$fileId}/content"),
            );

            foreach ($this->jsonLines($response) as $line) {
                if ($line instanceof BatchRequestFailed) {
                    yield $line->customId => $line;

                    continue;
                }

                $customId = (string) ($line['custom_id'] ?? '');

                yield $customId => $this->parseLine($customId, $line, $provider, $contexts, $structured);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array<string, RequestContext>  $contexts
     */
    protected function parseLine(string $customId, array $line, TextProvider $provider, array $contexts, ?bool $structured): mixed
    {
        $provider = $this->asProvider($provider);

        $statusCode = $line['response']['status_code'] ?? null;
        $body = $line['response']['body'] ?? [];

        if (filled($line['error'] ?? null) || ($statusCode !== null && $statusCode >= 400) || isset($body['error'])) {
            $error = $line['error'] ?? $body['error'] ?? [];

            return new BatchRequestFailed(
                customId: $customId,
                message: $error['message'] ?? 'The request failed without an error message.',
                type: BatchRequestFailureType::Errored,
                code: $error['code'] ?? $error['type'] ?? null,
                statusCode: $statusCode,
                raw: $line,
            );
        }

        try {
            $this->validateTextResponse($body);

            $step = $this->parseTextResponse(
                $body,
                $provider,
                $this->structuredFor($customId, $contexts, $structured, ($body['text']['format']['type'] ?? null) === 'json_schema'),
            );

            return $this->toAgentResponse($step, $this->invocationIdFor($customId, $contexts));
        } catch (Throwable $exception) {
            return new BatchRequestFailed(
                customId: $customId,
                message: $exception->getMessage(),
                type: BatchRequestFailureType::Errored,
                code: $exception instanceof AiException ? 'parse_error' : $exception::class,
                statusCode: $statusCode,
                raw: $line,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function toHandle(array $data, TextProvider $provider): BatchHandle
    {
        if (! isset($data['id'])) {
            throw new BatchException(sprintf(
                'OpenAI Error: [%s] %s',
                $data['error']['type'] ?? 'unknown',
                $data['error']['message'] ?? 'Unexpected batch response.',
            ));
        }

        $counts = $data['request_counts'] ?? [];

        return new BatchHandle(
            id: $data['id'],
            status: $this->mapStatus($data['status'] ?? ''),
            provider: $provider,
            counts: new BatchRequestCounts(
                total: $counts['total'] ?? 0,
                completed: $counts['completed'] ?? 0,
                failed: $counts['failed'] ?? 0,
                processing: max(0, ($counts['total'] ?? 0) - ($counts['completed'] ?? 0) - ($counts['failed'] ?? 0)),
            ),
            createdAt: $this->timestamp($data['created_at'] ?? null),
            expiresAt: $this->timestamp($data['expires_at'] ?? null),
            endedAt: $this->timestamp($data['completed_at'] ?? $data['failed_at'] ?? $data['expired_at'] ?? $data['cancelled_at'] ?? null),
            raw: $data,
            resultsAvailable: filled($data['output_file_id'] ?? null) || filled($data['error_file_id'] ?? null),
        );
    }

    protected function mapStatus(string $status): BatchStatus
    {
        return BatchStatus::tryFrom($status) ?? BatchStatus::Validating;
    }

    protected function timestamp(int|string|null $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::createFromTimestampUTC((int) $value);
    }
}
