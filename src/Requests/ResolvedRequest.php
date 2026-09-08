<?php

namespace AiBatch\Requests;

use Illuminate\Contracts\Support\Arrayable;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Prompts\AgentPrompt;

/**
 * @implements Arrayable<string, mixed>
 *
 * The exact request the SDK would send for an agent prompt, captured before sending.
 */
final class ResolvedRequest implements Arrayable
{
    /**
     * @param  array<string, mixed>  $body  exactly what generateTextStep() would POST
     * @param  array<string, mixed>|null  $schema  the agent's structured output schema, when any
     * @param  array<int, Message>  $messages  the messages after middleware and history assembly
     * @param  array<int, mixed>  $tools  the resolved tools
     */
    public function __construct(
        public readonly TextProvider $provider,
        public readonly string $model,
        public readonly string $endpoint,
        public readonly array $body,
        public readonly ?array $schema,
        public readonly AgentPrompt $prompt,
        public readonly string $invocationId,
        public readonly ?string $instructions = null,
        public readonly array $messages = [],
        public readonly array $tools = [],
    ) {}

    /**
     * Determine if the request expects structured output.
     */
    public function isStructured(): bool
    {
        return filled($this->schema);
    }

    /**
     * The context needed to interpret this request's result later, possibly in another process.
     */
    public function context(): RequestContext
    {
        return new RequestContext($this->invocationId, $this->isStructured(), $this->prompt->agent::class, $this->model);
    }

    /**
     * Format the request as an OpenAI-style batch input line.
     *
     * @return array{custom_id: string, method: string, url: string, body: array<string, mixed>}
     */
    public function toBatchLine(string $customId): array
    {
        return [
            'custom_id' => $customId,
            'method' => 'POST',
            'url' => $this->endpoint,
            'body' => $this->body,
        ];
    }

    public function toArray(): array
    {
        return [
            'provider' => $this->provider->name(),
            'model' => $this->model,
            'endpoint' => $this->endpoint,
            'body' => $this->body,
            'schema' => $this->schema,
            'invocation_id' => $this->invocationId,
        ];
    }
}
