<?php

use AiBatch\Batch;
use AiBatch\BatchHandle;
use AiBatch\BatchStatus;
use AiBatch\Contracts\BatchGateway;
use AiBatch\Gateway\FakeBatchGateway;
use AiBatch\Gateway\OpenAi\OpenAiBatchGateway;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Tests\Fixtures\Agents\AssistantAgent;

test('Agent::fake alone keeps batches off the network', function (): void {
    AssistantAgent::fake(['Canned']);

    $request = (new AssistantAgent)->resolve('Hello');

    // resolve() still builds the real provider body...
    expect($request->body)->toHaveKey('input')
        ->and($request->endpoint)->toBe('/v1/responses');

    // ...but submitting and reading results never leaves the process.
    $results = Batch::of(['a' => $request])->submit()->results();

    expect($results['a']->text)->toBe('Canned')
        ->and(Batch::isFaked())->toBeFalse();
});

test('a batch gateway swapped onto the provider wins over the driver map', function (): void {
    $custom = new class extends OpenAiBatchGateway
    {
        public array $submitted = [];

        public function __construct() {}

        public function submitBatch(TextProvider $provider, array $requests, array $options = []): BatchHandle
        {
            $this->submitted = array_keys($requests);

            return new BatchHandle('custom-1', BatchStatus::Validating, $provider);
        }
    };

    $provider = (clone Ai::textProvider('openai'))->useTextGateway($custom);

    expect(Batch::gatewayFor($provider))->toBe($custom);

    $request = (new AssistantAgent)->resolve('Hi');

    $handle = Batch::of(['x' => $request])->submit(provider: $provider);

    expect($handle->id)->toBe('custom-1')
        ->and($custom->submitted)->toBe(['x']);
});

test('gateways can be registered per driver at runtime', function (): void {
    config()->set('ai.providers.ollama', ['driver' => 'ollama', 'key' => '']);

    $gateway = new FakeBatchGateway;

    Batch::extend('ollama', fn () => $gateway);

    expect(Batch::gatewayFor(Ai::textProvider('ollama')))->toBe($gateway)
        ->and(Batch::gatewayFor(Ai::textProvider('ollama')))->toBe($gateway);
});

test('Batch::fake overrides everything', function (): void {
    $fake = Batch::fake();

    $provider = (clone Ai::textProvider('openai'))->useTextGateway(new OpenAiBatchGateway(app('events')));

    expect(Batch::gatewayFor($provider))->toBe($fake)
        ->and(Batch::requestResolverFor($provider))->toBeInstanceOf(OpenAiBatchGateway::class);
});

test('the request resolver contract can be used directly to build a body', function (): void {
    $provider = Ai::textProvider('openai');

    $body = Batch::requestResolverFor($provider)->resolveTextRequest(
        $provider, 'gpt-5.4-nano', 'Be brief', [new UserMessage('Hi')], [], null, new TextGenerationOptions(maxTokens: 12),
    );

    expect($body['model'])->toBe('gpt-5.4-nano')
        ->and($body['max_output_tokens'])->toBe(12)
        ->and(Batch::requestResolverFor($provider))->toBeInstanceOf(BatchGateway::class);
});
