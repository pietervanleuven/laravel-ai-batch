<?php

use AiBatch\Batch;
use AiBatch\BatchHandle;
use AiBatch\BatchManager;
use AiBatch\BatchRequestFailed;
use AiBatch\BatchRequestFailureType;
use AiBatch\BatchStatus;
use AiBatch\Exceptions\BatchNotReadyException;
use AiBatch\Requests\Resolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Tests\Fixtures\Agents\AnthropicAgent;
use Tests\Fixtures\Agents\StructuredAgent;

function anthropicBatch(string $status = 'in_progress', array $counts = [], array $extra = []): array
{
    return [
        'id' => 'msgbatch_1',
        'type' => 'message_batch',
        'processing_status' => $status,
        'request_counts' => ['processing' => 2, 'succeeded' => 0, 'errored' => 0, 'canceled' => 0, 'expired' => 0, ...$counts],
        'created_at' => '2026-09-08T10:00:00Z',
        'expires_at' => '2026-09-09T10:00:00Z',
        'ended_at' => null,
        'cancel_initiated_at' => null,
        'results_url' => null,
        ...$extra,
    ];
}

function anthropicResultLine(string $customId, array $content, string $stop = 'end_turn'): string
{
    return json_encode(['custom_id' => $customId, 'result' => ['type' => 'succeeded', 'message' => [
        'id' => 'msg_'.$customId, 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5',
        'content' => $content, 'stop_reason' => $stop, 'usage' => ['input_tokens' => 11, 'output_tokens' => 36],
    ]]]);
}

test('submitting sends inline requests', function (): void {
    Http::fake(['api.anthropic.com/v1/messages/batches' => Http::response(anthropicBatch())]);

    $batch = Batch::of([
        'one' => app(Resolver::class)->resolve(new AnthropicAgent, 'Text one'),
        'two' => app(Resolver::class)->resolve(new AnthropicAgent, 'Text two'),
    ])->submit();

    expect($batch->id)->toBe('msgbatch_1')
        ->and($batch->status)->toBe(BatchStatus::InProgress)
        ->and($batch->counts->total)->toBe(2)
        ->and($batch->createdAt?->toIso8601String())->toBe('2026-09-08T10:00:00+00:00');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.anthropic.com/v1/messages/batches'
        && $request->hasHeader('x-api-key', 'test-key')
        && $request['requests'][0]['custom_id'] === 'one'
        && $request['requests'][0]['params']['model'] === 'claude-sonnet-5'
        && $request['requests'][0]['params']['messages'][0]['content'][0]['text'] === 'Text one'
        && str_contains($request['requests'][1]['params']['system'], 'Summarise'));
});

test('status is derived from processing_status and counts', function (): void {
    Http::fake([
        'api.anthropic.com/v1/messages/batches/msgbatch_1/cancel' => Http::response(anthropicBatch('canceling')),
        'api.anthropic.com/v1/messages/batches/msgbatch_1' => Http::sequence()
            ->push(anthropicBatch('ended', ['processing' => 0, 'succeeded' => 2], ['ended_at' => '2026-09-08T11:00:00Z']))
            ->push(anthropicBatch('ended', ['processing' => 0, 'canceled' => 2], ['cancel_initiated_at' => '2026-09-08T10:30:00Z']))
            ->push(anthropicBatch('ended', ['processing' => 0, 'expired' => 2]))
            ->push(anthropicBatch('in_progress')),
    ]);

    expect(Batch::find('msgbatch_1', 'anthropic')->status)->toBe(BatchStatus::Completed)
        ->and(Batch::find('msgbatch_1', 'anthropic')->status)->toBe(BatchStatus::Cancelled)
        ->and(Batch::find('msgbatch_1', 'anthropic')->status)->toBe(BatchStatus::Expired)
        ->and(Batch::find('msgbatch_1', 'anthropic')->cancel()->status)->toBe(BatchStatus::Cancelling);
});

test('results are streamed from the results endpoint', function (): void {
    $lines = implode("\n", [
        anthropicResultLine('two', [['type' => 'text', 'text' => 'Second summary']]),
        anthropicResultLine('one', [['type' => 'text', 'text' => 'First summary']]),
        anthropicResultLine('structured', [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'output_structured_data', 'input' => ['sentiment' => 'mixed']]], 'tool_use'),
        json_encode(['custom_id' => 'bad', 'result' => ['type' => 'errored', 'error' => ['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'max_tokens too large']]]]),
        json_encode(['custom_id' => 'late', 'result' => ['type' => 'expired']]),
    ])."\n";

    Http::fake(['api.anthropic.com/v1/messages/batches/msgbatch_1/results' => Http::response($lines)]);

    $results = app(BatchManager::class)->results(
        new BatchHandle('msgbatch_1', BatchStatus::Completed, Ai::textProvider('anthropic'), resultsAvailable: true),
    );

    expect($results->keys()->all())->toBe(['two', 'one', 'structured', 'bad', 'late'])
        ->and($results['one'])->toBeInstanceOf(AgentResponse::class)
        ->and($results['one']->text)->toBe('First summary')
        ->and($results['one']->usage->completionTokens)->toBe(36)
        ->and($results['one']->meta->model)->toBe('claude-sonnet-5')
        ->and($results['structured'])->toBeInstanceOf(StructuredAgentResponse::class)
        ->and($results['structured']['sentiment'])->toBe('mixed')
        ->and($results['bad'])->toBeInstanceOf(BatchRequestFailed::class)
        ->and($results['bad']->code)->toBe('invalid_request_error')
        ->and($results['bad']->message)->toBe('max_tokens too large')
        ->and($results['late']->type)->toBe(BatchRequestFailureType::Expired);
});

test('native structured output can be forced when requests are unknown', function (): void {
    Http::fake(['api.anthropic.com/v1/messages/batches/msgbatch_1/results' => Http::response(
        anthropicResultLine('s', [['type' => 'text', 'text' => '{"sentiment":"positive"}']])."\n",
    )]);

    $handle = new BatchHandle('msgbatch_1', BatchStatus::Completed, Ai::textProvider('anthropic'), resultsAvailable: true);

    expect($handle->results()['s'])->toBeInstanceOf(AgentResponse::class)
        ->and($handle->results()['s'])->not->toBeInstanceOf(StructuredAgentResponse::class)
        ->and($handle->results(structured: true)['s'])->toBeInstanceOf(StructuredAgentResponse::class)
        ->and($handle->structured()->results()['s']['sentiment'])->toBe('positive');
});

test('native structured output is decoded off-process through the stored context', function (): void {
    Http::fake([
        'api.anthropic.com/v1/messages/batches' => Http::response(anthropicBatch()),
        'api.anthropic.com/v1/messages/batches/msgbatch_1/results' => Http::response(
            anthropicResultLine('s', [['type' => 'text', 'text' => '{"sentiment":"positive"}']])."\n",
        ),
    ]);

    $request = (new StructuredAgent)->resolve('Great stuff', provider: 'anthropic');

    expect($request->body['output_config']['format']['type'])->toBe('json_schema');

    Batch::of(['s' => $request])->submit();

    $result = app(BatchManager::class)->results(
        new BatchHandle('msgbatch_1', BatchStatus::Completed, Ai::textProvider('anthropic'), resultsAvailable: true),
    )['s'];

    expect($result)->toBeInstanceOf(StructuredAgentResponse::class)
        ->and($result['sentiment'])->toBe('positive')
        ->and($result->invocationId)->toBe($request->invocationId);
});

test('a cancelled batch with successes is Cancelled but still has results', function (): void {
    $lines = anthropicResultLine('one', [['type' => 'text', 'text' => 'Done before cancel']])."\n"
        .json_encode(['custom_id' => 'two', 'result' => ['type' => 'canceled']])."\n";

    Http::fake([
        'api.anthropic.com/v1/messages/batches/msgbatch_1/results' => Http::response($lines),
        'api.anthropic.com/v1/messages/batches/msgbatch_1' => Http::response(anthropicBatch('ended', ['processing' => 0, 'succeeded' => 1, 'canceled' => 1], [
            'cancel_initiated_at' => '2026-09-08T10:30:00Z',
            'ended_at' => '2026-09-08T10:31:00Z',
            'results_url' => 'https://api.anthropic.com/v1/messages/batches/msgbatch_1/results',
        ])),
    ]);

    $batch = Batch::find('msgbatch_1', 'anthropic');

    expect($batch->status)->toBe(BatchStatus::Cancelled)
        ->and($batch->hasResults())->toBeTrue()
        ->and($batch->counts->completed)->toBe(1)
        ->and($batch->counts->cancelled)->toBe(1);

    $results = $batch->results();

    expect($results['one']->text)->toBe('Done before cancel')
        ->and($results['two'])->toBeInstanceOf(BatchRequestFailed::class)
        ->and($results['two']->type)->toBe(BatchRequestFailureType::Canceled)
        ->and($results['two']->type->neverRan())->toBeTrue();
});

test('reading results before the batch ended throws', function (): void {
    Http::fake(['api.anthropic.com/v1/messages/batches/msgbatch_1' => Http::response(anthropicBatch('in_progress'))]);

    Batch::find('msgbatch_1', 'anthropic')->results();
})->throws(BatchNotReadyException::class);
