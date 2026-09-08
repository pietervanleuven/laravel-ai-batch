<?php

use AiBatch\Batch;
use AiBatch\BatchHandle;
use AiBatch\BatchResults;
use AiBatch\BatchStatus;
use AiBatch\Jobs\PollBatch;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Tests\Fixtures\Agents\AssistantAgent;

test('then and catch dispatch a PollBatch job with the callbacks attached', function (): void {
    Queue::fake();
    Batch::fake();

    Batch::of(['a' => (new AssistantAgent)->resolve('Hi')])
        ->submit()
        ->then(fn (BatchResults $results) => null)
        ->catch(fn (BatchHandle $batch) => null)
        ->every(15)
        ->onQueue('ai');

    Queue::assertPushedOn('ai', PollBatch::class, fn (PollBatch $job): bool => $job->batchId === 'fake-batch-1'
        && $job->providerName === 'openai'
        && $job->interval === 15
        && count($job->thenCallbacks) === 1
        && count($job->catchCallbacks) === 1);
});

test('the job releases itself until the batch is terminal, then runs the callbacks', function (): void {
    $fake = Batch::fake(['a' => 'Done']);

    $handle = Batch::of(['a' => (new AssistantAgent)->resolve('Hi')])->submit();

    $fake->setStatus($handle->id, BatchStatus::InProgress);

    $completed = null;
    $failed = null;

    $job = (new PollBatch($handle->id, 'openai', null, 5))
        ->then(function (BatchResults $results, BatchHandle $batch) use (&$completed): void {
            $completed = $results;
        })
        ->catch(function (?BatchHandle $batch) use (&$failed): void {
            $failed = $batch;
        });

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $queueJob->shouldReceive('release')->once()->with(5);
    $queueJob->shouldReceive('isReleased')->andReturn(false);
    $job->setJob($queueJob);

    $job->handle();

    expect($completed)->toBeNull()->and($failed)->toBeNull();

    $fake->setStatus($handle->id, BatchStatus::Completed);

    $job->handle();

    expect($completed)->toBeInstanceOf(BatchResults::class)
        ->and($completed['a']->text)->toBe('Done')
        ->and($failed)->toBeNull();
});

test('the job runs catch callbacks when the batch ends without completing', function (): void {
    $fake = Batch::fake();

    $handle = Batch::of(['a' => (new AssistantAgent)->resolve('Hi')])->submit();
    $fake->setStatus($handle->id, BatchStatus::Failed);

    $failed = null;

    (new PollBatch($handle->id, 'openai'))
        ->catch(function (?BatchHandle $batch, ?Throwable $exception) use (&$failed): void {
            $failed = [$batch, $exception];
        })
        ->handle();

    expect($failed[0]?->status)->toBe(BatchStatus::Failed)
        ->and($failed[1])->toBeNull();
});

test('the job runs then callbacks with partial results when a batch was cancelled after some requests ran', function (): void {
    $fake = Batch::fake(['a' => 'Ran before cancel']);

    $handle = Batch::of(['a' => (new AssistantAgent)->resolve('Hi')])->submit();
    $fake->setStatus($handle->id, BatchStatus::Cancelled);

    $completed = null;
    $failed = null;

    (new PollBatch($handle->id, 'openai'))
        ->then(function (BatchResults $results, BatchHandle $batch) use (&$completed): void {
            $completed = [$results, $batch];
        })
        ->catch(function (?BatchHandle $batch) use (&$failed): void {
            $failed = $batch;
        })
        ->handle();

    expect($failed)->toBeNull()
        ->and($completed[1]->status)->toBe(BatchStatus::Cancelled)
        ->and($completed[0]['a']->text)->toBe('Ran before cancel');
});

test('then and catch as separate statements share a single polling job', function (): void {
    Queue::fake();
    Batch::fake();

    $handle = Batch::of(['a' => (new AssistantAgent)->resolve('Hi')])->submit();

    $handle->then(fn () => null);
    $handle->catch(fn () => null);
    $handle->poll()->every(30)->onQueue('slow');

    unset($handle);

    Queue::assertPushed(PollBatch::class, 1);
    Queue::assertPushedOn('slow', PollBatch::class, fn (PollBatch $job): bool => count($job->thenCallbacks) === 1
        && count($job->catchCallbacks) === 1
        && $job->interval === 30);
});

test('provider errors while polling propagate so the worker retries after a full interval', function (): void {
    Http::fake(['api.openai.com/v1/batches/batch_x' => Http::response(['error' => ['message' => 'down']], 503)]);

    $job = new PollBatch('batch_x', 'openai', null, 45);

    expect($job->backoff())->toBe(45)
        ->and($job->maxExceptions)->toBeGreaterThan(0)
        ->and(fn () => $job->handle())->toThrow(ProviderOverloadedException::class);
});

test('giving up runs the catch callbacks with the exception and whatever handle can still be fetched', function (): void {
    Http::fake(['api.openai.com/v1/batches/batch_x' => Http::response(['error' => ['message' => 'gone']], 404)]);

    $seen = null;

    (new PollBatch('batch_x', 'openai'))
        ->catch(function (?BatchHandle $batch, ?Throwable $exception) use (&$seen): void {
            $seen = [$batch, $exception];
        })
        ->failed(new RuntimeException('max exceptions'));

    expect($seen[0])->toBeNull()
        ->and($seen[1])->toBeInstanceOf(RuntimeException::class);
});

test('a kept-alive handle can dispatch its polling job explicitly, once', function (): void {
    Queue::fake();
    Batch::fake();

    $handle = Batch::of(['a' => (new AssistantAgent)->resolve('Hi')])->submit();

    $handle->then(fn () => null)->dispatch();
    $handle->poll()->dispatch();

    Queue::assertPushed(PollBatch::class, 1);

    unset($handle);

    Queue::assertPushed(PollBatch::class, 1);
});

test('the poll delay doubles per attempt up to the maximum', function (): void {
    $job = new PollBatch('b', 'openai', null, 30, 200);

    $delays = [];

    foreach ([1, 2, 3, 4, 5, 40] as $attempt) {
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn($attempt);
        $job->setJob($queueJob);

        $delays[] = $job->nextDelay();
    }

    expect($delays)->toBe([30, 60, 120, 200, 200, 200]);

    $fromConfig = new PollBatch('b', 'openai', null, 60);
    $fromConfig->setJob(tap(Mockery::mock(Job::class), fn ($m) => $m->shouldReceive('attempts')->andReturn(10)));

    expect($fromConfig->nextDelay())->toBe(900);
});
