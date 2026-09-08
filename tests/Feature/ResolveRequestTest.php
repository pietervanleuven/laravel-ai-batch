<?php

use AiBatch\Exceptions\BatchException;
use AiBatch\Exceptions\UnsupportedBatchProviderException;
use AiBatch\Requests\ResolvedRequest;
use AiBatch\Requests\Resolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\Fixtures\Agents\AnthropicAgent;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\ConversationalAgent;
use Tests\Fixtures\Agents\RememberingAgent;
use Tests\Fixtures\Agents\StructuredAgent;

function openAiTextResponse(string $text = 'Hi'): array
{
    return [
        'id' => 'resp_1',
        'model' => 'gpt-5.4',
        'status' => 'completed',
        'output' => [[
            'type' => 'message',
            'status' => 'completed',
            'content' => [['type' => 'output_text', 'text' => $text]],
        ]],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ];
}

test('resolve returns the request without sending it', function (): void {
    Http::fake();

    $request = (new AssistantAgent)->resolve('Hello there');

    $default = Ai::textProvider('openai')->defaultTextModel();

    expect($request)->toBeInstanceOf(ResolvedRequest::class)
        ->and($request->provider->name())->toBe('openai')
        ->and($request->model)->toBe($default)
        ->and($request->endpoint)->toBe('/v1/responses')
        ->and($request->schema)->toBeNull()
        ->and($request->isStructured())->toBeFalse()
        ->and($request->body['model'])->toBe($default)
        ->and($request->body['max_output_tokens'])->toBe(512)
        ->and($request->body['temperature'])->toBe(0.2)
        ->and($request->prompt)->toBeInstanceOf(AgentPrompt::class)
        ->and($request->prompt->prompt)->toBe('Hello there')
        ->and($request->invocationId)->not->toBeEmpty();

    Http::assertNothingSent();
});

test('resolved body matches exactly what the synchronous path sends', function (): void {
    Http::fake(['api.openai.com/*' => Http::response(openAiTextResponse())]);

    (new AssistantAgent)->prompt('Compare me');

    $sent = null;
    Http::assertSent(function (Request $request) use (&$sent): bool {
        $sent = $request->data();

        return true;
    });

    $resolved = (new AssistantAgent)->resolve('Compare me');

    expect($resolved->body)->toBe($sent);
});

test('structured agents resolve their schema and response format', function (): void {
    $request = (new StructuredAgent)->resolve('I love this');

    expect($request->isStructured())->toBeTrue()
        ->and($request->schema)->toHaveKey('sentiment')
        ->and($request->body['text']['format']['type'])->toBe('json_schema')
        ->and($request->body['text']['format']['strict'])->toBeTrue();
});

test('provider and model can be overridden and agent attributes are honoured', function (): void {
    $request = (new AssistantAgent)->resolve('Hi', provider: Lab::Anthropic, model: 'claude-haiku-4-5-20251001');

    expect($request->provider->name())->toBe('anthropic')
        ->and($request->endpoint)->toBe('/v1/messages')
        ->and($request->body['model'])->toBe('claude-haiku-4-5-20251001')
        ->and($request->body['system'])->toContain('helpful assistant')
        ->and($request->body['max_tokens'])->toBe(512)
        ->and($request->body['messages'][0]['role'])->toBe('user');

    $viaAttribute = app(Resolver::class)->resolve(new AnthropicAgent, 'Hi');

    expect($viaAttribute->provider->name())->toBe('anthropic')
        ->and($viaAttribute->model)->toBe('claude-sonnet-5');
});

test('agent middleware runs before the request is resolved', function (): void {
    $agent = (new AssistantAgent)->withMiddleware([
        function (AgentPrompt $prompt, Closure $next) {
            return $next(new AgentPrompt(
                $prompt->agent, strtoupper($prompt->prompt), $prompt->attachments, $prompt->provider, $prompt->model, $prompt->timeout,
                invocationId: $prompt->invocationId,
            ));
        },
    ]);

    $request = $agent->resolve('shout');

    expect(json_encode($request->body['input']))->toContain('SHOUT')
        ->and(json_encode($request->body['input']))->not->toContain('shout');
});

test('resolving a faked agent records the prompt for assertions', function (): void {
    AssistantAgent::fake();

    (new AssistantAgent)->resolve('Recorded');

    AssistantAgent::assertPrompted('Recorded');
});

test('unsupported providers throw a clear exception', function (): void {
    config()->set('ai.providers.ollama', ['driver' => 'ollama', 'key' => '']);

    (new AssistantAgent)->resolve('Hi', provider: 'ollama');
})->throws(UnsupportedBatchProviderException::class);

test('resolving fires the SDK prompting events but never AgentFailed or AgentPrompted', function (): void {
    Event::fake([PromptingAgent::class, StartingStep::class, AgentFailed::class, AgentPrompted::class]);

    $request = (new AssistantAgent)->resolve('Hi');

    Event::assertDispatched(PromptingAgent::class, fn (PromptingAgent $event): bool => $event->invocationId === $request->invocationId);
    Event::assertDispatched(StartingStep::class, 1);
    Event::assertNotDispatched(AgentFailed::class);
    Event::assertNotDispatched(AgentPrompted::class);
});

test('conversational history and attachments resolve byte for byte like the synchronous path', function (): void {
    Http::fake(['api.openai.com/*' => Http::response(openAiTextResponse())]);

    $agent = new ConversationalAgent([
        new UserMessage('Earlier question'),
        new AssistantMessage('Earlier answer'),
    ]);

    $image = Image::fromBase64('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'image/png');

    $agent->prompt('Look at this', attachments: [$image]);

    $sent = null;
    Http::assertSent(function (Request $request) use (&$sent): bool {
        $sent = $request->data();

        return true;
    });

    $resolved = (new ConversationalAgent([
        new UserMessage('Earlier question'),
        new AssistantMessage('Earlier answer'),
    ]))->resolve('Look at this', attachments: [$image]);

    expect($resolved->body)->toBe($sent)
        ->and($resolved->messages)->toHaveCount(3)
        ->and($resolved->instructions)->toContain('remembers');
});

test('agents that remember conversations are rejected', function (): void {
    app(Resolver::class)->resolve(new RememberingAgent, 'Hi');
})->throws(BatchException::class, 'remembers conversations');

test('resolving does not consume Agent::fake responses', function (): void {
    AssistantAgent::fake(['Only response']);

    (new AssistantAgent)->resolve('Hi');

    expect((new AssistantAgent)->prompt('Hi')->text)->toBe('Only response');
});
