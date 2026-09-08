<?php

use AiBatch\Batch;
use AiBatch\BatchHandle;
use AiBatch\BatchManager;
use AiBatch\BatchRequestFailed;
use AiBatch\BatchStatus;
use AiBatch\Exceptions\BatchException;
use AiBatch\Exceptions\BatchNotReadyException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Tests\Fixtures\Agents\OpenRouterAgent;
use Tests\Fixtures\Agents\StructuredAgent;

function openRouterBatch(string $status = 'validating', array $counts = [], array $extra = []): array
{
    return [
        'id' => 'batch_1',
        'object' => 'batch',
        'endpoint' => '/v1/chat/completions',
        'model' => 'anthropic/claude-sonnet-5',
        'completion_window' => '24h',
        'status' => $status,
        'created_at' => 1782097200,
        'finalized_at' => null,
        'request_counts' => ['total' => 2, 'completed' => 0, 'failed' => 0, ...$counts],
        'usage' => null,
        'results' => null,
        'error' => null,
        ...$extra,
    ];
}

function openRouterResult(string $customId, string $text): array
{
    return [
        'id' => 'batch_req_'.$customId,
        'custom_id' => $customId,
        'response' => [
            'status_code' => 200,
            'request_id' => 'req_'.$customId,
            'body' => [
                'id' => 'gen-'.$customId,
                'object' => 'chat.completion',
                'model' => 'anthropic/claude-sonnet-5',
                'choices' => [['message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 36, 'total_tokens' => 47],
            ],
        ],
        'error' => null,
    ];
}

test('submitting sends inline requests to the beta batches endpoint', function (): void {
    Http::fake(['openrouter.ai/api/beta/batches' => Http::response(openRouterBatch(), 202)]);

    $batch = Batch::of([
        'one' => (new OpenRouterAgent)->resolve('Text one'),
        'two' => (new OpenRouterAgent)->resolve('Text two'),
    ])->submit();

    expect($batch->id)->toBe('batch_1')
        ->and($batch->status)->toBe(BatchStatus::Validating)
        ->and($batch->counts->total)->toBe(2)
        ->and($batch->counts->processing)->toBe(2)
        ->and($batch->createdAt?->toIso8601String())->toBe('2026-06-22T03:00:00+00:00');

    Http::assertSent(function (Request $request): bool {
        $keys = array_keys($request->data());

        return $request->url() === 'https://openrouter.ai/api/beta/batches'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            // OpenRouter stream-parses the body: endpoint and model must precede requests.
            && $keys === ['endpoint', 'model', 'requests']
            && $request['endpoint'] === '/v1/chat/completions'
            && $request['model'] === 'anthropic/claude-sonnet-5'
            && $request['requests'][0]['custom_id'] === 'one'
            // The model is carried once at the batch level, not per request.
            && ! isset($request['requests'][0]['body']['model'])
            && $request['requests'][0]['body']['messages'][1]['content'] === 'Text one'
            && str_contains($request['requests'][1]['body']['messages'][0]['content'], 'Summarise');
    });
});

test('a batch cannot mix models', function (): void {
    Http::fake();

    Batch::of([
        'one' => (new OpenRouterAgent)->resolve('Text one'),
        'two' => (new OpenRouterAgent)->resolve('Text two', model: 'openai/gpt-4o'),
    ])->submit();
})->throws(BatchException::class, 'single model');

test('statuses map straight onto the batch status enum', function (): void {
    Http::fake([
        'openrouter.ai/api/beta/batches/batch_1' => Http::sequence()
            ->push(openRouterBatch('in_progress', ['completed' => 1]))
            ->push(openRouterBatch('completed', ['completed' => 2], ['finalized_at' => 1782100800]))
            ->push(openRouterBatch('expired', ['completed' => 1]))
            ->push(openRouterBatch('failed')),
    ]);

    $inProgress = Batch::find('batch_1', 'openrouter');
    $completed = Batch::find('batch_1', 'openrouter');
    $expired = Batch::find('batch_1', 'openrouter');
    $failed = Batch::find('batch_1', 'openrouter');

    expect($inProgress->status)->toBe(BatchStatus::InProgress)
        ->and($inProgress->counts->processing)->toBe(1)
        ->and($completed->status)->toBe(BatchStatus::Completed)
        ->and($completed->isCompleted())->toBeTrue()
        ->and($completed->endedAt?->toIso8601String())->toBe('2026-06-22T04:00:00+00:00')
        ->and($expired->status)->toBe(BatchStatus::Expired)
        ->and($expired->counts->expired)->toBe(1)
        ->and($failed->hasFailed())->toBeTrue();
});

test('cancelling is refused rather than guessing at an endpoint OpenRouter does not document', function (): void {
    Http::fake(['openrouter.ai/api/beta/batches/batch_1' => Http::response(openRouterBatch('in_progress'))]);

    Batch::find('batch_1', 'openrouter')->cancel();
})->throws(BatchException::class, 'does not expose a batch cancel endpoint');

test('results are read inline from the completed batch', function (): void {
    Http::fake(['openrouter.ai/api/beta/batches/batch_1' => Http::response(openRouterBatch('completed', ['completed' => 2, 'failed' => 1], [
        'results' => [
            openRouterResult('two', 'Second summary'),
            openRouterResult('one', 'First summary'),
            [
                'id' => 'batch_req_bad',
                'custom_id' => 'bad',
                'response' => ['status_code' => 400, 'body' => ['error' => ['code' => 'invalid_request_error', 'message' => 'max_tokens too large']]],
                'error' => null,
            ],
        ],
    ]))]);

    $results = app(BatchManager::class)->results(
        new BatchHandle('batch_1', BatchStatus::Completed, Ai::textProvider('openrouter')),
    );

    expect($results->keys()->all())->toBe(['two', 'one', 'bad'])
        ->and($results['one'])->toBeInstanceOf(AgentResponse::class)
        ->and($results['one']->text)->toBe('First summary')
        ->and($results['one']->usage->completionTokens)->toBe(36)
        ->and($results['one']->meta->model)->toBe('anthropic/claude-sonnet-5')
        ->and($results['bad'])->toBeInstanceOf(BatchRequestFailed::class)
        ->and($results['bad']->code)->toBe('invalid_request_error')
        ->and($results['bad']->statusCode)->toBe(400)
        ->and($results['bad']->message)->toBe('max_tokens too large');
});

test('structured output follows the original request, or an explicit override', function (): void {
    Http::fake(['openrouter.ai/api/beta/batches*' => Http::response(openRouterBatch('completed', ['completed' => 1], [
        'results' => [openRouterResult('s', '{"sentiment":"positive"}')],
    ]))]);

    $request = (new StructuredAgent)->resolve('I loved it', provider: 'openrouter');

    expect($request->body['response_format']['type'])->toBe('json_schema');

    $handle = new BatchHandle('batch_1', BatchStatus::Completed, Ai::textProvider('openrouter'));

    expect($handle->withRequests(['s' => $request])->results()['s'])->toBeInstanceOf(StructuredAgentResponse::class)
        ->and($handle->withRequests([])->results()['s'])->not->toBeInstanceOf(StructuredAgentResponse::class)
        ->and($handle->results(structured: true)['s']['sentiment'])->toBe('positive');
});

test('results fetched in another process keep invocation ids and structured flags through the store', function (): void {
    Http::fake([
        'openrouter.ai/api/beta/batches' => Http::response(openRouterBatch(), 202),
        'openrouter.ai/api/beta/batches/batch_1' => Http::response(openRouterBatch('completed', ['completed' => 2], [
            'results' => [openRouterResult('a', 'Plain'), openRouterResult('b', '{"sentiment":"negative"}')],
        ])),
    ]);

    $requests = [
        'a' => (new OpenRouterAgent)->resolve('Plain please'),
        'b' => (new StructuredAgent)->resolve('This is awful', provider: 'openrouter'),
    ];

    Batch::of($requests)->submit();

    expect(DB::table('ai_batch_requests')->where('batch_id', 'batch_1')->count())->toBe(2);

    // A fresh handle, as PollBatch or a scheduler would build it, knows nothing in-process.
    $results = Batch::find('batch_1', 'openrouter')->results();

    expect($results['a']->invocationId)->toBe($requests['a']->invocationId)
        ->and($results['a'])->not->toBeInstanceOf(StructuredAgentResponse::class)
        ->and($results['b'])->toBeInstanceOf(StructuredAgentResponse::class)
        ->and($results['b']['sentiment'])->toBe('negative')
        ->and($results['b']->invocationId)->toBe($requests['b']->invocationId);
});

test('reading results before the batch has any throws instead of returning an empty collection', function (): void {
    Http::fake(['openrouter.ai/api/beta/batches/batch_1' => Http::response(openRouterBatch('in_progress'))]);

    Batch::find('batch_1', 'openrouter')->results();
})->throws(BatchNotReadyException::class, 'status: in_progress');

test('a cancelled batch reports no results at all, unlike the file-based providers', function (): void {
    // OpenRouter returns "results": null for anything but a completed batch, so there are no
    // partial results to salvage from a batch that was cancelled or expired part way through.
    Http::fake(['openrouter.ai/api/beta/batches/batch_1' => Http::response(openRouterBatch('cancelled', ['completed' => 1]))]);

    $batch = Batch::find('batch_1', 'openrouter');

    expect($batch->status)->toBe(BatchStatus::Cancelled)
        ->and($batch->counts->cancelled)->toBe(1)
        ->and($batch->hasResults())->toBeFalse();

    $batch->results();
})->throws(BatchNotReadyException::class, 'status: cancelled');

test('a custom provider url is respected when locating the beta batch endpoint', function (): void {
    config()->set('ai.providers.openrouter.url', 'https://gateway.test/proxy/v1');

    Http::fake(['gateway.test/proxy/beta/batches/batch_1' => Http::response(openRouterBatch('in_progress'))]);

    expect(Batch::find('batch_1', 'openrouter')->status)->toBe(BatchStatus::InProgress);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://gateway.test/proxy/beta/batches/batch_1');
});
