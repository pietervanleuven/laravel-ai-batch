<?php

namespace AiBatch\Exceptions;

use AiBatch\BatchHandle;

class BatchNotReadyException extends BatchException
{
    public static function forBatch(BatchHandle $batch): self
    {
        return new self(sprintf(
            'Batch [%s] on provider [%s] has no results yet (status: %s).',
            $batch->id,
            $batch->provider->name(),
            $batch->status->value,
        ));
    }
}
