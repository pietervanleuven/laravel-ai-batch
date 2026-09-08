<?php

namespace AiBatch\Gateway;

use AiBatch\Contracts\ResolvesTextRequests;
use AiBatch\Exceptions\RequestCaptured;
use Generator;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;

/**
 * A step gateway that records the first generation step instead of sending it.
 *
 * Installed on a cloned provider through useTextGateway(), it lets the SDK run its complete
 * prompt path (middleware, message assembly, tool resolution, abandoned tool call settlement)
 * and captures the exact arguments the real gateway would have received.
 *
 * @internal
 */
class CapturingTextGateway implements StepTextGateway
{
    /**
     * @var array<string, mixed>|null
     */
    public ?array $captured = null;

    public function __construct(protected ResolvesTextRequests $resolver) {}

    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        $this->captured = [
            'provider' => $provider,
            'model' => $model,
            'instructions' => $instructions,
            'messages' => $messages,
            'tools' => $tools,
            'schema' => $schema,
            'options' => $options,
            'timeout' => $timeout,
            'body' => $this->resolver->resolveTextRequest($provider, $model, $instructions, $messages, $tools, $schema, $options),
            'endpoint' => $this->resolver->textEndpoint(),
        ];

        throw new RequestCaptured('Request captured.');
    }

    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        yield from [];

        return $this->generateTextStep($provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout, $stepContext);
    }
}
