<?php

namespace AiBatch\Contracts;

use AiBatch\BatchHandle;
use AiBatch\BatchRequestFailed;
use AiBatch\Requests\RequestContext;
use AiBatch\Requests\ResolvedRequest;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Responses\AgentResponse;

interface BatchGateway extends ResolvesTextRequests
{
    /**
     * Submit the given resolved requests as a single provider batch.
     *
     * @param  array<string, ResolvedRequest>  $requests  keyed by custom id
     * @param  array<string, mixed>  $options  provider-specific submission options
     */
    public function submitBatch(TextProvider $provider, array $requests, array $options = []): BatchHandle;

    /**
     * Retrieve the current state of a batch.
     */
    public function retrieveBatch(TextProvider $provider, string $id): BatchHandle;

    /**
     * Iterate the results of a finished batch, keyed by custom id.
     *
     * Results are never thrown mid-iteration: a request that failed yields a BatchRequestFailed.
     *
     * @param  array<string, RequestContext>  $contexts  per-request context (invocation id, structured flag) when known
     * @param  bool|null  $structured  force structured decoding for every result; null uses the context, then provider auto-detection
     * @return iterable<string, AgentResponse|BatchRequestFailed>
     */
    public function batchResults(TextProvider $provider, BatchHandle $batch, array $contexts = [], ?bool $structured = null): iterable;

    /**
     * Cancel a running batch.
     */
    public function cancelBatch(TextProvider $provider, string $id): BatchHandle;
}
