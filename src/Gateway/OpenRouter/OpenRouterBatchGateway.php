<?php

namespace AiBatch\Gateway\OpenRouter;

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
use AiBatch\Gateway\Concerns\ResolvesRequestContext;
use AiBatch\Requests\RequestContext;
use AiBatch\Requests\ResolvedRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\OpenRouter\OpenRouterGateway;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;
use Throwable;

/**
 * OpenRouter Batch API: inline requests under a batch-level endpoint and model, results returned
 * inline on the batch object once it completes.
 *
 * Extends the SDK gateway so chat completion bodies and result parsing go through the exact same
 * protected builders the synchronous path uses.
 */
class OpenRouterBatchGateway extends OpenRouterGateway implements BatchGateway
{
    use BuildsAgentResponses, NarrowsProviders, ResolvesRequestContext;

    public function textEndpoint(): string
    {
        return '/v1/chat/completions';
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
            if ($request->endpoint !== $this->textEndpoint()) {
                throw new BatchException("Request [{$customId}] targets [{$request->endpoint}]; an OpenRouter batch must use a single endpoint [{$this->textEndpoint()}].");
            }

            // The model is carried once at the batch level, so it is stripped from each body.
            $payload[] = ['custom_id' => (string) $customId, 'body' => Arr::except($request->body, ['model'])];
        }

        $data = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->batchClient($provider, $options['timeout'] ?? 120)->post('batches', [
                // OpenRouter stream-parses the body and rejects payloads whose "requests"
                // array is serialized before "endpoint" and "model".
                'endpoint' => $this->textEndpoint(),
                'model' => $this->batchModel($requests),
                'requests' => $payload,
            ])->json(),
        );

        return $this->toHandle($data, $provider);
    }

    public function retrieveBatch(TextProvider $provider, string $id): BatchHandle
    {
        $provider = $this->asProvider($provider);

        $data = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->batchClient($provider)->get("batches/{$id}")->json(),
        );

        return $this->toHandle($data, $provider);
    }

    /**
     * OpenRouter documents only submit, list and retrieve; there is no cancel endpoint, even though
     * a batch can reach the "cancelling" / "cancelled" statuses by other means.
     */
    public function cancelBatch(TextProvider $provider, string $id): BatchHandle
    {
        $provider = $this->asProvider($provider);

        throw new BatchException('OpenRouter does not expose a batch cancel endpoint. Cancel the batch from the OpenRouter dashboard; its status is reported here once it changes.');
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

        foreach ($batch->raw['results'] ?? [] as $result) {
            $customId = (string) ($result['custom_id'] ?? '');

            yield $customId => $this->parseResult($customId, $result, $provider, $contexts, $structured);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, RequestContext>  $contexts
     */
    protected function parseResult(string $customId, array $result, TextProvider $provider, array $contexts, ?bool $structured): mixed
    {
        $provider = $this->asProvider($provider);

        $statusCode = $result['response']['status_code'] ?? null;
        $body = $result['response']['body'] ?? [];

        if (filled($result['error'] ?? null) || ($statusCode !== null && $statusCode >= 400) || isset($body['error'])) {
            $error = $result['error'] ?? $body['error'] ?? [];

            return new BatchRequestFailed(
                customId: $customId,
                message: $error['message'] ?? 'The request failed without an error message.',
                type: BatchRequestFailureType::Errored,
                code: $error['code'] ?? $error['type'] ?? null,
                statusCode: $statusCode,
                raw: $result,
            );
        }

        try {
            $this->validateTextResponse($body);

            $step = $this->parseTextResponse(
                $body,
                $provider,
                // A chat completion never echoes its response_format, so structured decoding can
                // only come from the original request or an explicit override.
                $this->structuredFor($customId, $contexts, $structured, false),
            );

            return $this->toAgentResponse($step, $this->invocationIdFor($customId, $contexts));
        } catch (Throwable $exception) {
            return new BatchRequestFailed(
                customId: $customId,
                message: $exception->getMessage(),
                type: BatchRequestFailureType::Errored,
                code: $exception instanceof AiException ? 'parse_error' : $exception::class,
                statusCode: $statusCode,
                raw: $result,
            );
        }
    }

    /**
     * An OpenRouter batch runs a single model, taken from the requests it carries.
     *
     * @param  array<string, ResolvedRequest>  $requests
     */
    protected function batchModel(array $requests): string
    {
        $models = array_unique(array_map(fn (ResolvedRequest $request): string => $request->model, $requests));

        if (count($models) !== 1) {
            throw new BatchException($models === []
                ? 'An OpenRouter batch requires at least one request.'
                : 'An OpenRouter batch runs a single model, but the requests target ['.implode(', ', $models).'].');
        }

        return reset($models);
    }

    /**
     * The batch API lives under /api/beta rather than the /api/v1 base the SDK client uses.
     */
    protected function batchClient(TextProvider $provider, ?int $timeout = null): PendingRequest
    {
        $provider = $this->asProvider($provider);

        return $this->client($provider, $timeout)->baseUrl($this->batchBaseUrl($provider));
    }

    protected function batchBaseUrl(Provider $provider): string
    {
        $url = $this->baseUrl($provider);

        return str_ends_with($url, '/v1') ? substr($url, 0, -3).'/beta' : $url;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function toHandle(array $data, TextProvider $provider): BatchHandle
    {
        if (! isset($data['id'])) {
            throw new BatchException(sprintf(
                'OpenRouter Error: [%s] %s',
                $data['error']['type'] ?? $data['error']['code'] ?? 'unknown',
                $data['error']['message'] ?? 'Unexpected batch response.',
            ));
        }

        $counts = $data['request_counts'] ?? [];

        $total = $counts['total'] ?? 0;
        $completed = $counts['completed'] ?? 0;
        $failed = $counts['failed'] ?? 0;

        $status = BatchStatus::tryFrom($data['status'] ?? '') ?? BatchStatus::Validating;

        return new BatchHandle(
            id: $data['id'],
            status: $status,
            provider: $provider,
            counts: new BatchRequestCounts(
                total: $total,
                completed: $completed,
                failed: $failed,
                processing: max(0, $total - $completed - $failed),
                cancelled: $status === BatchStatus::Cancelled ? max(0, $total - $completed - $failed) : 0,
                expired: $status === BatchStatus::Expired ? max(0, $total - $completed - $failed) : 0,
            ),
            createdAt: $this->timestamp($data['created_at'] ?? null),
            // OpenRouter reports no expiry; a terminal batch carries "finalized_at".
            endedAt: $this->timestamp($data['finalized_at'] ?? null),
            raw: $data,
            // "results" is only ever an array on a completed batch: a cancelled, expired or failed
            // batch reports null, so there are no partial results to read.
            resultsAvailable: is_array($data['results'] ?? null),
        );
    }

    protected function timestamp(int|string|null $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::createFromTimestampUTC((int) $value);
    }
}
