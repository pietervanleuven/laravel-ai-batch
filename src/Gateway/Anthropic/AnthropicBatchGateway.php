<?php

namespace AiBatch\Gateway\Anthropic;

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
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\Anthropic\AnthropicGateway;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Throwable;

/**
 * Anthropic Message Batches API: inline requests, results streamed back as JSONL.
 */
class AnthropicBatchGateway extends AnthropicGateway implements BatchGateway
{
    use BuildsAgentResponses, NarrowsProviders, ParsesJsonLines, ResolvesRequestContext;

    public function textEndpoint(): string
    {
        return '/v1/messages';
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

        $payload = [];

        foreach ($requests as $customId => $request) {
            $payload[] = ['custom_id' => (string) $customId, 'params' => $request->body];
        }

        $data = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $options['timeout'] ?? 120)
                ->post('messages/batches', ['requests' => $payload])
                ->json(),
        );

        return $this->toHandle($data, $provider);
    }

    public function retrieveBatch(TextProvider $provider, string $id): BatchHandle
    {
        $provider = $this->asProvider($provider);

        $data = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)->get("messages/batches/{$id}")->json(),
        );

        return $this->toHandle($data, $provider);
    }

    public function cancelBatch(TextProvider $provider, string $id): BatchHandle
    {
        $provider = $this->asProvider($provider);

        $data = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)->post("messages/batches/{$id}/cancel")->json(),
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

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, 300)->withOptions(['stream' => true])->get("messages/batches/{$batch->id}/results"),
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

    /**
     * @param  array<string, mixed>  $line
     * @param  array<string, RequestContext>  $contexts
     */
    protected function parseLine(string $customId, array $line, TextProvider $provider, array $contexts, ?bool $structured): mixed
    {
        $provider = $this->asProvider($provider);

        $result = $line['result'] ?? [];
        $type = $result['type'] ?? 'errored';

        if ($type !== 'succeeded') {
            $error = $result['error']['error'] ?? $result['error'] ?? [];

            return new BatchRequestFailed(
                customId: $customId,
                message: $error['message'] ?? match ($type) {
                    'canceled' => 'The batch was canceled before this request was processed.',
                    'expired' => 'The batch expired before this request was processed.',
                    default => 'The request failed without an error message.',
                },
                type: BatchRequestFailureType::tryFrom($type) ?? BatchRequestFailureType::Errored,
                code: $error['type'] ?? null,
                raw: $line,
            );
        }

        $message = $result['message'] ?? [];

        try {
            $this->validateTextResponse($message);

            $detected = false;

            foreach ($message['content'] ?? [] as $block) {
                if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === 'output_structured_data') {
                    $detected = true;

                    break;
                }
            }

            $step = $this->parseTextResponse(
                $message,
                $provider,
                $this->structuredFor($customId, $contexts, $structured, $detected),
            );

            return $this->toAgentResponse($step, $this->invocationIdFor($customId, $contexts));
        } catch (Throwable $exception) {
            return new BatchRequestFailed(
                customId: $customId,
                message: $exception->getMessage(),
                type: BatchRequestFailureType::Errored,
                code: 'parse_error',
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
                'Anthropic Error: [%s] %s',
                $data['error']['type'] ?? 'unknown',
                $data['error']['message'] ?? 'Unexpected batch response.',
            ));
        }

        $counts = $data['request_counts'] ?? [];

        $processing = $counts['processing'] ?? 0;
        $succeeded = $counts['succeeded'] ?? 0;
        $errored = $counts['errored'] ?? 0;
        $canceled = $counts['canceled'] ?? 0;
        $expired = $counts['expired'] ?? 0;

        return new BatchHandle(
            id: $data['id'],
            status: $this->mapStatus($data, $succeeded, $processing),
            provider: $provider,
            counts: new BatchRequestCounts(
                total: $processing + $succeeded + $errored + $canceled + $expired,
                completed: $succeeded,
                failed: $errored,
                processing: $processing,
                cancelled: $canceled,
                expired: $expired,
            ),
            createdAt: $this->timestamp($data['created_at'] ?? null),
            expiresAt: $this->timestamp($data['expires_at'] ?? null),
            endedAt: $this->timestamp($data['ended_at'] ?? null),
            raw: $data,
            resultsAvailable: filled($data['results_url'] ?? null),
        );
    }

    /**
     * Anthropic only reports in_progress / canceling / ended; derive the finer states from the counts.
     *
     * @param  array<string, mixed>  $data
     */
    protected function mapStatus(array $data, int $succeeded, int $processing): BatchStatus
    {
        return match ($data['processing_status'] ?? '') {
            'in_progress' => BatchStatus::InProgress,
            'canceling' => BatchStatus::Cancelling,
            'ended' => match (true) {
                filled($data['cancel_initiated_at'] ?? null) => BatchStatus::Cancelled,
                $succeeded === 0 && $processing === 0 && ($data['request_counts']['expired'] ?? 0) > 0 => BatchStatus::Expired,
                default => BatchStatus::Completed,
            },
            default => BatchStatus::Validating,
        };
    }

    protected function timestamp(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value);
    }
}
