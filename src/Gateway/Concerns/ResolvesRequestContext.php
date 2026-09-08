<?php

namespace AiBatch\Gateway\Concerns;

use AiBatch\Requests\RequestContext;
use Illuminate\Support\Str;

trait ResolvesRequestContext
{
    /**
     * Decide whether a result should be decoded as structured output.
     *
     * @param  array<string, RequestContext>  $contexts
     */
    protected function structuredFor(string $customId, array $contexts, ?bool $structured, bool $detected): bool
    {
        if ($structured !== null) {
            return $structured;
        }

        if (isset($contexts[$customId])) {
            return $contexts[$customId]->structured;
        }

        return $detected;
    }

    /**
     * Reuse the invocation id assigned at resolve time when the request is known.
     *
     * @param  array<string, RequestContext>  $contexts
     */
    protected function invocationIdFor(string $customId, array $contexts): string
    {
        return $contexts[$customId]->invocationId ?? (string) Str::uuid7();
    }
}
