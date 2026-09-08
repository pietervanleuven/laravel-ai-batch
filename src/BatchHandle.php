<?php

namespace AiBatch;

use AiBatch\Requests\RequestContext;
use AiBatch\Requests\ResolvedRequest;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Responses\AgentResponse;

/**
 * @implements Arrayable<string, mixed>
 *
 * A provider-agnostic view of a submitted batch.
 */
class BatchHandle implements Arrayable
{
    /**
     * The original resolved requests, when the handle was produced in the submitting process.
     *
     * @var array<string, ResolvedRequest>
     */
    public array $requests = [];

    /**
     * Whether results should be decoded as structured output when the requests are unknown.
     */
    public ?bool $structured = null;

    /**
     * The single polling job this handle dispatches, shared by then() and catch().
     */
    protected ?PendingBatchPoll $poll = null;

    /**
     * @param  array<string, mixed>  $raw  the provider's batch object
     */
    public function __construct(
        public readonly string $id,
        public readonly BatchStatus $status,
        public readonly TextProvider $provider,
        public readonly BatchRequestCounts $counts = new BatchRequestCounts,
        public readonly ?CarbonImmutable $createdAt = null,
        public readonly ?CarbonImmutable $expiresAt = null,
        public readonly ?CarbonImmutable $endedAt = null,
        public readonly array $raw = [],
        public readonly bool $resultsAvailable = false,
    ) {}

    /**
     * Attach the original requests so results carry their invocation ids and structured flags.
     *
     * @param  array<string, ResolvedRequest>  $requests
     */
    public function withRequests(array $requests): static
    {
        $this->requests = $requests;

        return $this;
    }

    /**
     * Decode every result as structured output (or not) when the original requests are unknown.
     */
    public function structured(bool $structured = true): static
    {
        $this->structured = $structured;

        return $this;
    }

    /**
     * Determine if the batch reached a terminal state.
     */
    public function isFinished(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * Determine if the batch finished and produced results.
     */
    public function isCompleted(): bool
    {
        return $this->status === BatchStatus::Completed;
    }

    /**
     * Determine if the batch ended without completing.
     */
    public function hasFailed(): bool
    {
        return $this->isFinished() && ! $this->isCompleted();
    }

    /**
     * Determine if the provider has results to read, including partial results of a cancelled or expired batch.
     */
    public function hasResults(): bool
    {
        return $this->resultsAvailable;
    }

    /**
     * Re-fetch the batch from the provider, keeping the in-process request context.
     */
    public function refresh(): BatchHandle
    {
        return $this->carryContextTo(app(BatchManager::class)->retrieve($this->id, $this->provider));
    }

    /**
     * Cancel the batch.
     */
    public function cancel(): BatchHandle
    {
        return $this->carryContextTo(app(BatchManager::class)->cancel($this->id, $this->provider));
    }

    /**
     * The per-request context known in this process, keyed by custom id.
     *
     * @return array<string, RequestContext>
     */
    public function contexts(): array
    {
        return array_map(fn (ResolvedRequest $request) => $request->context(), $this->requests);
    }

    /**
     * Fetch and parse the results, keyed by custom id.
     */
    public function results(?bool $structured = null): BatchResults
    {
        return app(BatchManager::class)->results($this, $structured ?? $this->structured);
    }

    /**
     * Stream the results one at a time to the callback, without holding them all in memory.
     *
     * @param  Closure(AgentResponse|BatchRequestFailed, string): void  $callback
     */
    public function each(Closure $callback, ?bool $structured = null): static
    {
        foreach (app(BatchManager::class)->iterateResults($this, $structured ?? $this->structured) as $customId => $result) {
            $callback($result, $customId);
        }

        return $this;
    }

    /**
     * Poll the batch on the queue and run the callback with its results once the provider has them.
     *
     * @param  Closure(BatchResults, BatchHandle): void  $callback
     */
    public function then(Closure $callback): PendingBatchPoll
    {
        return $this->poll()->then($callback);
    }

    /**
     * Poll the batch on the queue and run the callback if it ends with nothing to read or polling gives up.
     *
     * @param  Closure(?BatchHandle, ?\Throwable): void  $callback
     */
    public function catch(Closure $callback): PendingBatchPoll
    {
        return $this->poll()->catch($callback);
    }

    /**
     * The polling job for this handle; repeated then() / catch() calls share it.
     */
    public function poll(?int $interval = null): PendingBatchPoll
    {
        return $this->poll ??= new PendingBatchPoll($this, $interval);
    }

    /**
     * @internal
     */
    public function carryContextTo(BatchHandle $handle): BatchHandle
    {
        $handle->requests = $this->requests;
        $handle->structured = $this->structured;

        return $handle;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'provider' => $this->provider->name(),
            'counts' => $this->counts->toArray(),
            'created_at' => $this->createdAt?->toIso8601String(),
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'ended_at' => $this->endedAt?->toIso8601String(),
            'results_available' => $this->resultsAvailable,
        ];
    }
}
