<?php

namespace AiBatch;

use AiBatch\Exceptions\BatchException;
use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, mixed>
 *
 * A typed, non-throwing failure for a single request inside a batch.
 */
final class BatchRequestFailed implements Arrayable
{
    /**
     * @param  array<string, mixed>  $raw  the provider's error body
     */
    public function __construct(
        public readonly string $customId,
        public readonly string $message,
        public readonly BatchRequestFailureType $type = BatchRequestFailureType::Errored,
        public readonly ?string $code = null,
        public readonly ?int $statusCode = null,
        public readonly array $raw = [],
    ) {}

    public function toException(): BatchException
    {
        return new BatchException(sprintf('[%s] %s: %s', $this->customId, $this->code ?? $this->type->value, $this->message));
    }

    public function toArray(): array
    {
        return [
            'custom_id' => $this->customId,
            'type' => $this->type->value,
            'code' => $this->code,
            'status_code' => $this->statusCode,
            'message' => $this->message,
            'raw' => $this->raw,
        ];
    }
}
