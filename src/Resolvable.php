<?php

namespace AiBatch;

use AiBatch\Requests\ResolvedRequest;
use AiBatch\Requests\Resolver;
use Laravel\Ai\Enums\Lab;

/**
 * Opt-in trait for agents: resolve the provider request without sending it.
 */
trait Resolvable
{
    /**
     * Resolve the request that prompt() would send, without sending it.
     *
     * @param  array<int, mixed>  $attachments
     * @param  Lab|array<int|string, Lab|string|null>|string|null  $provider
     */
    public function resolve(
        string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): ResolvedRequest {
        return app(Resolver::class)->resolve($this, $prompt, $attachments, $provider, $model, $timeout);
    }
}
