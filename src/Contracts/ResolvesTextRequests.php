<?php

namespace AiBatch\Contracts;

use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;

/**
 * A gateway that can expose the request body it would send for a text generation step.
 */
interface ResolvesTextRequests
{
    /**
     * The provider's canonical path for a text generation request (e.g. "/v1/responses").
     */
    public function textEndpoint(): string;

    /**
     * Build the exact request body the synchronous path would POST, without sending it.
     *
     * @param  array<int, Message>  $messages
     * @param  array<int, mixed>  $tools
     * @param  array<string, mixed>|null  $schema
     * @return array<string, mixed>
     */
    public function resolveTextRequest(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
    ): array;
}
