<?php

use AiBatch\Batch;
use AiBatch\BatchRequestFailed;
use AiBatch\BatchStatus;
use AiBatch\Exceptions\BatchException;
use AiBatch\Requests\ResolvedRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Responses\StructuredAgentResponse;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\StructuredAgent;

test('faked batches complete immediately and record submissions', function (): void {
    Http::fake();
    Batch::fake();

    $batch = Batch::of([
        'a' => (new AssistantAgent)->resolve('One'),
        'b' => (new StructuredAgent)->resolve('Two'),
    ])->submit(options: ['metadata' => ['x' => 1]]);

    expect($batch->status)->toBe(BatchStatus::Completed)
        ->and($batch->isFinished())->toBeTrue();

    $results = $batch->results();

    expect($results['a']->text)->toContain('Fake batch response')
        ->and($results['b'])->toBeInstanceOf(StructuredAgentResponse::class)
        ->and($results['b']['sentiment'])->toBeString();

    Batch::assertSubmittedTimes(1);
    Batch::assertSubmitted(fn (array $requests, TextProvider $provider, array $options): bool => array_keys($requests) === ['a', 'b']
        && $provider->name() === 'openai'
        && $options['metadata'] === ['x' => 1]);

    Http::assertNothingSent();
});

test('fake results come from Agent::fake when configured', function (): void {
    Batch::fake();
    AssistantAgent::fake(['Canned one', fn (string $prompt): string => 'Canned for '.$prompt]);

    $results = Batch::of([
        'a' => (new AssistantAgent)->resolve('One'),
        'b' => (new AssistantAgent)->resolve('Two'),
    ])->submit()->results();

    expect($results['a']->text)->toBe('Canned one')
        ->and($results['b']->text)->toBe('Canned for Two');

    AssistantAgent::assertPromptedTimes(2);
});

test('fake results can be keyed by custom id, including failures', function (): void {
    Batch::fake([
        'ok' => 'Keyed response',
        'json' => ['sentiment' => 'neutral'],
        'broken' => new BatchRequestFailed('broken', 'Simulated failure', code: 'boom'),
    ]);

    $results = Batch::of([
        'ok' => (new AssistantAgent)->resolve('One'),
        'json' => (new StructuredAgent)->resolve('Two'),
        'broken' => (new AssistantAgent)->resolve('Three'),
    ])->submit()->results();

    expect($results['ok']->text)->toBe('Keyed response')
        ->and($results['json']['sentiment'])->toBe('neutral')
        ->and($results['broken'])->toBeInstanceOf(BatchRequestFailed::class)
        ->and($results->failed()->keys()->all())->toBe(['broken']);
});

test('fake responses can be a closure receiving the custom id and request', function (): void {
    Batch::fake(fn (string $customId, ResolvedRequest $request): string => $customId.':'.$request->prompt->prompt);

    $results = Batch::of(['x' => (new AssistantAgent)->resolve('Hello')])->submit()->results();

    expect($results['x']->text)->toBe('x:Hello');
});

test('assertions fail when nothing matches', function (): void {
    Batch::fake();

    Batch::assertNothingSubmitted();

    Batch::of(['x' => (new AssistantAgent)->resolve('Hello')])->submit();

    expect(fn () => Batch::assertNothingSubmitted())->toThrow(AssertionFailedError::class);
    expect(fn () => Batch::assertSubmitted(fn (): bool => false))->toThrow(AssertionFailedError::class);
});

test('sequence responses are consumed in order even for numeric custom ids', function (): void {
    Batch::fake(['first', 'second', 'third']);

    $results = Batch::of([
        '1' => (new AssistantAgent)->resolve('a'),
        '5' => (new AssistantAgent)->resolve('b'),
        '2' => (new AssistantAgent)->resolve('c'),
    ])->submit()->results();

    expect($results->keys()->all())->toEqual([1, 5, 2])
        ->and($results['1']->text)->toBe('first')
        ->and($results['5']->text)->toBe('second')
        ->and($results['2']->text)->toBe('third');
});

test('keyed responses match numeric custom ids', function (): void {
    Batch::fake(['7' => 'seven', 'x' => 'ex']);

    $results = Batch::of([
        '7' => (new AssistantAgent)->resolve('a'),
        'x' => (new AssistantAgent)->resolve('b'),
    ])->submit()->results();

    expect($results['7']->text)->toBe('seven')
        ->and($results['x']->text)->toBe('ex');
});

test('agents can be added with a prompt and are resolved on the spot', function (): void {
    Batch::fake();

    $pending = Batch::of()
        ->add('one', new AssistantAgent, 'First prompt')
        ->add('two', new StructuredAgent, 'Second prompt', provider: 'anthropic');

    $requests = $pending->requests();

    expect($requests['one']->prompt->prompt)->toBe('First prompt')
        ->and($requests['one']->provider->name())->toBe('openai')
        ->and($requests['two']->provider->name())->toBe('anthropic')
        ->and($requests['two']->isStructured())->toBeTrue();

    expect(fn () => $pending->add('three', new AssistantAgent))
        ->toThrow(BatchException::class, 'prompt is required');

    expect(fn () => $pending->add('one', (new AssistantAgent)->resolve('dupe')))
        ->toThrow(BatchException::class, 'Duplicate custom id');
});
