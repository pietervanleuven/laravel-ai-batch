<?php

namespace AiBatch\Gateway\Concerns;

use AiBatch\Exceptions\BatchException;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Providers\Provider;

/**
 * The SDK's request builders, parsers and HTTP clients are typed against its concrete Provider base
 * class, while the batch contracts speak to the TextProvider interface. Every shipped provider is both.
 */
trait NarrowsProviders
{
    /**
     * @return Provider&TextProvider
     */
    protected function asProvider(TextProvider $provider): Provider
    {
        if (! $provider instanceof Provider) {
            throw new BatchException(sprintf(
                'Provider [%s] must extend %s to be used with the %s gateway.',
                $provider::class, Provider::class, static::class,
            ));
        }

        return $provider;
    }
}
