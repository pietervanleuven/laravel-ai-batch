<?php

namespace AiBatch\Contracts;

use AiBatch\BatchHandle;
use AiBatch\Requests\RequestContext;
use AiBatch\Requests\ResolvedRequest;

/**
 * Persists the per-request context of a submitted batch so results can be interpreted in another process.
 */
interface BatchStore
{
    /**
     * @param  array<string, ResolvedRequest>  $requests  keyed by custom id
     */
    public function store(BatchHandle $batch, array $requests): void;

    /**
     * @return array<string, RequestContext> keyed by custom id; empty when the batch is unknown
     */
    public function contexts(string $batchId, string $provider): array;

    public function forget(string $batchId, string $provider): void;
}
