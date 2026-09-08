<?php

use AiBatch\Batch;
use AiBatch\BatchHandle;
use AiBatch\BatchStatus;
use AiBatch\Contracts\BatchStore;
use AiBatch\Requests\RequestContext;
use AiBatch\Storage\ArrayBatchStore;
use AiBatch\Storage\DatabaseBatchStore;
use Laravel\Ai\Ai;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\StructuredAgent;

dataset('stores', [
    'database' => fn () => app(DatabaseBatchStore::class),
    'array' => fn () => new ArrayBatchStore,
]);

test('stores round-trip request context per provider and batch', function (BatchStore $store): void {
    $openai = new BatchHandle('b1', BatchStatus::Validating, Ai::textProvider('openai'));
    $anthropic = new BatchHandle('b1', BatchStatus::Validating, Ai::textProvider('anthropic'));

    $requests = [
        '1' => (new AssistantAgent)->resolve('one'),
        'two' => (new StructuredAgent)->resolve('two'),
    ];

    $store->store($openai, $requests);
    $store->store($anthropic, ['x' => (new AssistantAgent)->resolve('x', provider: 'anthropic')]);

    $contexts = $store->contexts('b1', 'openai');

    expect(array_keys($contexts))->toEqual([1, 'two'])
        ->and($contexts['1'])->toBeInstanceOf(RequestContext::class)
        ->and($contexts['1']->invocationId)->toBe($requests['1']->invocationId)
        ->and($contexts['1']->structured)->toBeFalse()
        ->and($contexts['two']->structured)->toBeTrue()
        ->and($contexts['two']->agent)->toBe(StructuredAgent::class)
        ->and($store->contexts('b1', 'anthropic'))->toHaveKeys(['x'])
        ->and($store->contexts('missing', 'openai'))->toBe([]);

    $store->forget('b1', 'openai');

    expect($store->contexts('b1', 'openai'))->toBe([])
        ->and($store->contexts('b1', 'anthropic'))->toHaveCount(1);
})->with('stores');

test('the store can be switched through config', function (): void {
    config()->set('ai-batch.store', 'array');
    app()->forgetInstance(BatchStore::class);

    expect(app(BatchStore::class))->toBeInstanceOf(ArrayBatchStore::class);
});

test('faked batches still write context to the store', function (): void {
    Batch::fake();

    $handle = Batch::of(['a' => (new AssistantAgent)->resolve('Hi')])->submit();

    expect(Batch::store()->contexts($handle->id, 'openai'))->toHaveKey('a');
});
