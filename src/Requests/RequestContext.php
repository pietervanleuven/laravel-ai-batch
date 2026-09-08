<?php

namespace AiBatch\Requests;

/**
 * The minimum needed to interpret a batch result: which invocation it belongs to and how to decode it.
 */
final class RequestContext
{
    public function __construct(
        public readonly string $invocationId,
        public readonly bool $structured,
        public readonly ?string $agent = null,
        public readonly ?string $model = null,
    ) {}
}
