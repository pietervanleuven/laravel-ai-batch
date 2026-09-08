<?php

namespace AiBatch\Exceptions;

use Laravel\Ai\Contracts\Providers\Provider;

class UnsupportedBatchProviderException extends BatchException
{
    public static function forProvider(Provider $provider): self
    {
        return new self(sprintf(
            'Provider [%s] (driver [%s]) has no batch gateway. Register one in config/ai-batch.php under "gateways".',
            $provider->name(),
            $provider->driver(),
        ));
    }
}
