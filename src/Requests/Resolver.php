<?php

namespace AiBatch\Requests;

use AiBatch\BatchManager;
use AiBatch\Exceptions\BatchException;
use AiBatch\Exceptions\RequestCaptured;
use AiBatch\Gateway\CapturingTextGateway;
use Closure;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Prompts\AgentPrompt;
use RuntimeException;

/**
 * Resolves the request an agent would send by running the SDK's own prompt path against a
 * capturing gateway, so middleware, history assembly, tool resolution and abandoned tool call
 * settlement all happen exactly as they do for a synchronous prompt.
 *
 * Provider, model and timeout precedence live in protected Promptable helpers; those three are
 * reached through {@see self::callProtected()} until the SDK exposes them (laravel/ai#767).
 */
class Resolver
{
    public function __construct(protected BatchManager $manager) {}

    /**
     * Resolve the request an agent would send for the given prompt without sending it.
     *
     * Dispatches the SDK's PromptingAgent and StartingStep events as a real prompt would.
     *
     * @param  array<int, mixed>  $attachments
     * @param  Lab|array<int|string, Lab|string|null>|string|null  $provider
     */
    public function resolve(
        Agent $agent,
        string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): ResolvedRequest {
        if (in_array(RemembersConversations::class, class_uses_recursive($agent), true)) {
            throw new BatchException(sprintf(
                'Agent [%s] remembers conversations; batch results are not written back to the conversation store, so it cannot be batched.',
                $agent::class,
            ));
        }

        [$provider, $model] = $this->resolveProviderAndModel($agent, $provider, $model);

        $capturing = new CapturingTextGateway($this->manager->requestResolverFor($provider));

        // Prompt through a clone so the capturing gateway never leaks onto the shared provider;
        // the resolved request keeps the original, whose gateway reflects Agent::fake() and user swaps.
        $capturingProvider = (clone $provider)->useTextGateway($capturing);

        $invocationId = (string) Str::uuid7();

        $agentPrompt = new AgentPrompt(
            $agent,
            $prompt,
            $attachments,
            $capturingProvider,
            $model,
            $this->callProtected($agent, 'getTimeout', $timeout),
            invocationId: $invocationId,
            isFinalAttempt: false,
        );

        try {
            $capturingProvider->prompt($agentPrompt);
        } catch (RequestCaptured) {
            //
        }

        if ($capturing->captured === null) {
            throw new BatchException(sprintf('The prompt for agent [%s] never reached the gateway; nothing to resolve.', $agent::class));
        }

        $captured = $capturing->captured;

        return new ResolvedRequest(
            provider: $provider,
            model: $captured['model'],
            endpoint: $captured['endpoint'],
            body: $captured['body'],
            schema: $captured['schema'],
            prompt: $agentPrompt,
            invocationId: $invocationId,
            instructions: $captured['instructions'],
            messages: $captured['messages'],
            tools: $captured['tools'],
        );
    }

    /**
     * Resolve the first configured provider / model pair, using the agent's own attribute and method precedence.
     *
     * @param  Lab|array<int|string, Lab|string|null>|string|null  $provider
     * @return array{TextProvider, string}
     */
    protected function resolveProviderAndModel(Agent $agent, Lab|array|string|null $provider, ?string $model): array
    {
        $pairs = $this->callProtected($agent, 'getProvidersAndModels', $provider, $model);

        if (empty($pairs)) {
            throw new RuntimeException('No AI providers were configured.');
        }

        $name = array_key_first($pairs);

        $resolved = Ai::textProviderFor($agent, $name);

        return [$resolved, $pairs[$name] ?? $this->callProtected($agent, 'getDefaultModelFor', $resolved)];
    }

    /**
     * Invoke a protected Promptable helper on the agent.
     */
    protected function callProtected(object $target, string $method, mixed ...$arguments): mixed
    {
        if (! method_exists($target, $method)) {
            throw new RuntimeException(sprintf(
                'The installed laravel/ai version does not define [%s::%s]; this package needs updating.',
                $target::class,
                $method,
            ));
        }

        return Closure::bind(
            fn () => $this->{$method}(...$arguments),
            $target,
            $target::class,
        )();
    }
}
