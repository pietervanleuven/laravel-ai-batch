<?php

namespace AiBatch;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, mixed>
 */
final class BatchRequestCounts implements Arrayable
{
    public function __construct(
        public readonly int $total = 0,
        public readonly int $completed = 0,
        public readonly int $failed = 0,
        public readonly int $processing = 0,
        public readonly int $cancelled = 0,
        public readonly int $expired = 0,
    ) {}

    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'completed' => $this->completed,
            'failed' => $this->failed,
            'processing' => $this->processing,
            'cancelled' => $this->cancelled,
            'expired' => $this->expired,
        ];
    }
}
