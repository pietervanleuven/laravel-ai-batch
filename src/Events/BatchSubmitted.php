<?php

namespace AiBatch\Events;

use AiBatch\BatchHandle;
use AiBatch\Requests\ResolvedRequest;

class BatchSubmitted
{
    /**
     * @param  array<string, ResolvedRequest>  $requests
     */
    public function __construct(
        public BatchHandle $batch,
        public array $requests,
    ) {}
}
