<?php

namespace AiBatch;

use AiBatch\Jobs\PollBatch;
use Closure;
use Illuminate\Foundation\Bus\PendingDispatch;

/**
 * A PollBatch job waiting to be dispatched. Queue configuration (onQueue, onConnection, delay, afterCommit ...)
 * comes from PendingDispatch; the job is dispatched when this object goes out of scope.
 */
class PendingBatchPoll extends PendingDispatch
{
    protected bool $dispatched = false;

    public function __construct(BatchHandle $batch, ?int $interval = null, ?int $maxInterval = null)
    {
        parent::__construct(new PollBatch(
            $batch->id,
            $batch->provider->name(),
            $batch->structured,
            $interval ?? (int) config('ai-batch.poll_interval', 60),
            $maxInterval,
        ));
    }

    /**
     * Run the callback with the results once the provider has them.
     *
     * @param  Closure(BatchResults, BatchHandle): void  $callback
     */
    public function then(Closure $callback): static
    {
        $this->job->then($callback);

        return $this;
    }

    /**
     * Run the callback if the batch ends with nothing to read, or polling gives up.
     *
     * @param  Closure(?BatchHandle, ?\Throwable): void  $callback
     */
    public function catch(Closure $callback): static
    {
        $this->job->catch($callback);

        return $this;
    }

    /**
     * Change how many seconds the job waits before its first re-check. The delay doubles on each
     * check up to $max (default config('ai-batch.poll_max_interval')).
     */
    public function every(int $seconds, ?int $max = null): static
    {
        $this->job->interval = $seconds;

        if ($max !== null) {
            $this->job->maxInterval = $max;
        }

        return $this;
    }

    /**
     * Dispatch the job now instead of when this object goes out of scope. Useful when the
     * batch handle is kept alive (stored on a long-lived object) after registering callbacks.
     */
    public function dispatch(): void
    {
        if ($this->dispatched) {
            return;
        }

        $this->dispatched = true;

        parent::__destruct();
    }

    public function __destruct()
    {
        $this->dispatch();
    }

    public function getJob(): PollBatch
    {
        return $this->job;
    }
}
