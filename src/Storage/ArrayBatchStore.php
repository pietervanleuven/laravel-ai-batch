<?php

namespace AiBatch\Storage;

use AiBatch\BatchHandle;
use AiBatch\Contracts\BatchStore;
use AiBatch\Requests\RequestContext;
use AiBatch\Requests\ResolvedRequest;

/**
 * Keeps context for the lifetime of the process only.
 */
class ArrayBatchStore implements BatchStore
{
    /**
     * @var array<string, array<string, RequestContext>>
     */
    protected array $contexts = [];

    public function store(BatchHandle $batch, array $requests): void
    {
        $this->contexts[$this->key($batch->id, $batch->provider->name())] = array_map(
            fn (ResolvedRequest $request) => $request->context(),
            $requests,
        );
    }

    public function contexts(string $batchId, string $provider): array
    {
        return $this->contexts[$this->key($batchId, $provider)] ?? [];
    }

    public function forget(string $batchId, string $provider): void
    {
        unset($this->contexts[$this->key($batchId, $provider)]);
    }

    protected function key(string $batchId, string $provider): string
    {
        return $provider.':'.$batchId;
    }
}
