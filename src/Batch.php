<?php

namespace AiBatch;

use Illuminate\Support\Facades\Facade;

/**
 * @method static PendingBatch of(array<string, \AiBatch\Requests\ResolvedRequest> $requests = [])
 * @method static BatchHandle find(string $id, \Laravel\Ai\Enums\Lab|string|\Laravel\Ai\Contracts\Providers\TextProvider|null $provider = null)
 * @method static \AiBatch\Contracts\ResolvesTextRequests requestResolverFor(\Laravel\Ai\Contracts\Providers\TextProvider $provider)
 * @method static \AiBatch\Contracts\BatchGateway gatewayFor(\Laravel\Ai\Contracts\Providers\TextProvider $provider)
 * @method static void forget(BatchHandle $batch)
 * @method static \AiBatch\Contracts\BatchStore store()
 * @method static BatchManager extend(string $driver, \Closure $resolver)
 * @method static \AiBatch\Gateway\FakeBatchGateway fake(\Closure|array<string|int, mixed> $responses = [])
 * @method static bool isFaked()
 * @method static void assertSubmitted(\Closure $callback)
 * @method static void assertSubmittedTimes(int $times = 1)
 * @method static void assertNothingSubmitted()
 *
 * @see BatchManager
 */
class Batch extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BatchManager::class;
    }
}
