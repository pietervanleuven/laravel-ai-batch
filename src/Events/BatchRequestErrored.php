<?php

namespace AiBatch\Events;

use AiBatch\BatchHandle;
use AiBatch\BatchRequestFailed;

class BatchRequestErrored
{
    public function __construct(
        public ?string $invocationId,
        public string $customId,
        public BatchHandle $batch,
        public BatchRequestFailed $failure,
    ) {}
}
