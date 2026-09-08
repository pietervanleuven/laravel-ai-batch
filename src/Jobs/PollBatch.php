<?php

namespace AiBatch\Jobs;

use AiBatch\Batch;
use AiBatch\BatchHandle;
use AiBatch\BatchResults;
use Closure;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Laravel\SerializableClosure\SerializableClosure;
use Throwable;

/**
 * Re-releases itself until the batch is terminal, then runs the registered callbacks.
 *
 * The then callbacks run whenever the provider has results to read, including the partial
 * results of a cancelled or expired batch (requests that never ran appear as BatchRequestFailed).
 * The catch callbacks run when the batch ended with nothing to read, or when polling gave up
 * because the provider kept failing or the poll timeout passed.
 */
class PollBatch implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    /**
     * Unlimited attempts; retryUntil() bounds the job in time instead.
     */
    public int $tries = 0;

    /**
     * Give up after this many provider errors, so a broken batch id does not poll for the whole window.
     */
    public int $maxExceptions = 10;

    /**
     * @var array<int, SerializableClosure>
     */
    public array $thenCallbacks = [];

    /**
     * @var array<int, SerializableClosure>
     */
    public array $catchCallbacks = [];

    public function __construct(
        public string $batchId,
        public string $providerName,
        public ?bool $structured = null,
        public int $interval = 60,
        public ?int $maxInterval = null,
    ) {}

    /**
     * Seconds to wait before the next check: the interval, doubling on each check up to the maximum,
     * so a 24-hour batch is not polled every minute for a day.
     */
    public function nextDelay(): int
    {
        $max = $this->maxInterval ?? (int) config('ai-batch.poll_max_interval', 900);

        $exponent = max(0, min($this->attempts() - 1, 16));

        return (int) min($this->interval * (2 ** $exponent), max($max, $this->interval));
    }

    /**
     * @param  Closure(BatchResults, BatchHandle): void  $callback
     */
    public function then(Closure $callback): static
    {
        $this->thenCallbacks[] = new SerializableClosure($callback);

        return $this;
    }

    /**
     * @param  Closure(?BatchHandle, ?Throwable): void  $callback
     */
    public function catch(Closure $callback): static
    {
        $this->catchCallbacks[] = new SerializableClosure($callback);

        return $this;
    }

    /**
     * Provider errors thrown here are left to the worker, which retries after backoff().
     */
    public function handle(): void
    {
        $batch = Batch::find($this->batchId, $this->providerName);

        if (! $batch->isFinished()) {
            $this->release($this->nextDelay());

            return;
        }

        if ($batch->hasResults()) {
            $results = $batch->results($this->structured);

            foreach ($this->thenCallbacks as $callback) {
                $callback->getClosure()($results, $batch);
            }

            return;
        }

        $this->runCatchCallbacks($batch, null);
    }

    /**
     * Called by the worker once maxExceptions or retryUntil() is exceeded.
     */
    public function failed(?Throwable $exception = null): void
    {
        $batch = rescue(fn (): BatchHandle => Batch::find($this->batchId, $this->providerName), null, report: false);

        $this->runCatchCallbacks($batch, $exception);
    }

    /**
     * Wait a full poll interval before retrying after a provider error.
     */
    public function backoff(): int
    {
        return $this->interval;
    }

    /**
     * Stop polling once the provider's window plus finalisation slack has passed.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours((int) config('ai-batch.poll_timeout_hours', 48));
    }

    public function displayName(): string
    {
        return static::class.':'.$this->batchId;
    }

    protected function runCatchCallbacks(?BatchHandle $batch, ?Throwable $exception): void
    {
        foreach ($this->catchCallbacks as $callback) {
            $callback->getClosure()($batch, $exception);
        }
    }
}
