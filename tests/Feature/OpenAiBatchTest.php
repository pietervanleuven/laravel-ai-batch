<?php

use AiBatch\Batch;
use AiBatch\BatchHandle;
use AiBatch\BatchRequestFailed;
use AiBatch\BatchStatus;
use AiBatch\Events\BatchRequestCompleted;
use AiBatch\Events\BatchRequestErrored;
use AiBatch\Events\BatchSubmitted;
use AiBatch\Exceptions\BatchException;
use AiBatch\Exceptions\BatchNotReadyException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\FileStored;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Files;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\StructuredAgent;

function openAiBatch(string $status = 'validating', array $extra = []): array
{
    return [
        'id' => 'batch_abc',
        'object' => 'batch',
        'endpoint' => '/v1/responses',
        'status' => $status,
        'input_file_id' => 'file-in',
        'completion_window' => '24h',
        'created_at' => 1_700_000_000,
        'expires_at' => 1_700_086_400,
        'request_counts' => ['total' => 2, 'completed' => 0, 'failed' => 0],
        ...$extra,
    ];
}

function openAiOutputLine(string $customId, string $text, bool $structured = false): string
{
    $body = [
        'id' => 'resp_'.$customId,
        'model' => 'gpt-5.4',
        'status' => 'completed',
        'output' => [['type' => 'message', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => $text]]]],
        'usage' => ['input_tokens' => 12, 'output_tokens' => 4, 'input_tokens_details' => ['cached_tokens' => 2]],
    ];

    if ($structured) {
        $body['text'] = ['format' => ['type' => 'json_schema', 'name' => 'schema_definition']];
    }

    return json_encode(['id' => 'req_'.$customId, 'custom_id' => $customId, 'response' => ['status_code' => 200, 'request_id' => 'r', 'body' => $body], 'error' => null]);
}

test('submitting uploads a JSONL file and creates a batch', function (): void {
    Event::fake([BatchSubmitted::class, PromptingAgent::class]);

    Http::fake([
        'api.openai.com/v1/files' => Http::response(['id' => 'file-in', 'purpose' => 'batch']),
        'api.openai.com/v1/batches' => Http::response(openAiBatch()),
    ]);

    $batch = Batch::of([
        'post-1' => (new AssistantAgent)->resolve('First'),
        'post-2' => (new AssistantAgent)->resolve('Second'),
    ])->submit(options: ['metadata' => ['job' => 'nightly']]);

    expect($batch)->toBeInstanceOf(BatchHandle::class)
        ->and($batch->id)->toBe('batch_abc')
        ->and($batch->status)->toBe(BatchStatus::Validating)
        ->and($batch->isFinished())->toBeFalse()
        ->and($batch->counts->total)->toBe(2)
        ->and($batch->expiresAt?->timestamp)->toBe(1_700_086_400)
        ->and($batch->requests)->toHaveKeys(['post-1', 'post-2']);

    Http::assertSent(function (Request $request): bool {
        if (! str_ends_with($request->url(), '/files')) {
            return false;
        }

        $body = (string) $request->body();

        $upload = collect($request->data())->firstWhere('name', 'file');
        $lines = array_values(array_filter(explode("\n", (string) $upload['contents'])));

        expect($lines)->toHaveCount(2);

        $first = json_decode($lines[0], true);

        expect($first['custom_id'])->toBe('post-1')
            ->and($first['method'])->toBe('POST')
            ->and($first['url'])->toBe('/v1/responses')
            ->and(json_encode($first['body']['input']))->toContain('First');

        return $request->hasHeader('Authorization', 'Bearer test-key')
            && collect($request->data())->firstWhere('name', 'purpose')['contents'] === 'batch';
    });

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/batches')
        && $request['input_file_id'] === 'file-in'
        && $request['endpoint'] === '/v1/responses'
        && $request['completion_window'] === '24h'
        && $request['metadata'] === ['job' => 'nightly']);

    Event::assertDispatched(PromptingAgent::class, 2);
    Event::assertDispatched(BatchSubmitted::class, fn (BatchSubmitted $event): bool => $event->batch->id === 'batch_abc' && count($event->requests) === 2);
});

test('batches can be found, refreshed and cancelled', function (): void {
    Http::fake([
        'api.openai.com/v1/batches/batch_abc/cancel' => Http::response(openAiBatch('cancelling')),
        'api.openai.com/v1/batches/batch_abc' => Http::sequence()
            ->push(openAiBatch('in_progress'))
            ->push(openAiBatch('completed', ['output_file_id' => 'file-out', 'request_counts' => ['total' => 2, 'completed' => 2, 'failed' => 0]]))
            ->push(openAiBatch('in_progress')),
    ]);

    $batch = Batch::find('batch_abc', 'openai');

    expect($batch->status)->toBe(BatchStatus::InProgress)
        ->and($batch->counts->processing)->toBe(2);

    $batch = $batch->refresh();

    expect($batch->status)->toBe(BatchStatus::Completed)
        ->and($batch->isCompleted())->toBeTrue()
        ->and($batch->counts->completed)->toBe(2);

    expect(Batch::find('batch_abc', 'openai')->cancel()->status)->toBe(BatchStatus::Cancelling);
});

test('results parse through the SDK response parser and never throw mid-iteration', function (): void {
    Event::fake([BatchRequestCompleted::class, BatchRequestErrored::class, AgentPrompted::class]);

    $errorLine = json_encode(['id' => 'req_3', 'custom_id' => 'post-3', 'response' => ['status_code' => 400, 'body' => ['error' => ['code' => 'invalid_request', 'message' => 'Bad prompt']]], 'error' => null]);

    Http::fake([
        'api.openai.com/v1/batches/batch_abc' => Http::response(openAiBatch('completed', ['output_file_id' => 'file-out', 'error_file_id' => 'file-err'])),
        'api.openai.com/v1/files/file-out/content' => Http::response(openAiOutputLine('post-1', 'Hello one')."\n".openAiOutputLine('post-2', '{"sentiment":"positive"}', structured: true)."\n"),
        'api.openai.com/v1/files/file-err/content' => Http::response($errorLine."\n"),
    ]);

    $results = Batch::find('batch_abc', 'openai')->results();

    expect($results)->toHaveCount(3)
        ->and($results['post-1'])->toBeInstanceOf(AgentResponse::class)
        ->and($results['post-1']->text)->toBe('Hello one')
        ->and($results['post-1']->usage->promptTokens)->toBe(10)
        ->and($results['post-1']->usage->cacheReadInputTokens)->toBe(2)
        ->and($results['post-1']->meta->provider)->toBe('openai')
        ->and($results['post-1']->steps)->toHaveCount(1)
        ->and($results['post-2'])->toBeInstanceOf(StructuredAgentResponse::class)
        ->and($results['post-2']['sentiment'])->toBe('positive')
        ->and($results['post-3'])->toBeInstanceOf(BatchRequestFailed::class)
        ->and($results['post-3']->code)->toBe('invalid_request')
        ->and($results['post-3']->statusCode)->toBe(400)
        ->and($results->successful())->toHaveCount(2)
        ->and($results->failed())->toHaveCount(1)
        ->and($results->hasFailures())->toBeTrue();

    Event::assertDispatched(BatchRequestCompleted::class, 2);
    Event::assertDispatched(BatchRequestErrored::class, 1);
    Event::assertNotDispatched(AgentPrompted::class);

    expect(fn () => $results->throw())->toThrow(BatchException::class, 'Bad prompt');
});

test('in-process results reuse invocation ids, structured flags and fire AgentPrompted', function (): void {
    Event::fake([AgentPrompted::class]);

    Http::fake([
        'api.openai.com/v1/files' => Http::response(['id' => 'file-in']),
        'api.openai.com/v1/batches' => Http::response(openAiBatch()),
        'api.openai.com/v1/batches/batch_abc' => Http::response(openAiBatch('completed', ['output_file_id' => 'file-out'])),
        'api.openai.com/v1/files/file-out/content' => Http::response(openAiOutputLine('a', 'Plain')."\n".openAiOutputLine('b', '{"sentiment":"negative"}')."\n"),
    ]);

    $requests = [
        'a' => (new AssistantAgent)->resolve('Plain please'),
        'b' => (new StructuredAgent)->resolve('This is awful'),
    ];

    $batch = Batch::of($requests)->submit();
    $results = $batch->refresh()->results();

    expect($results['a']->invocationId)->toBe($requests['a']->invocationId)
        ->and($results['b'])->toBeInstanceOf(StructuredAgentResponse::class)
        ->and($results['b']->structured)->toBe(['sentiment' => 'negative']);

    Event::assertDispatched(AgentPrompted::class, 2);
    Event::assertDispatched(AgentPrompted::class, fn (AgentPrompted $event): bool => $event->invocationId === $requests['b']->invocationId
        && $event->prompt->prompt === 'This is awful');
});

test('submit validates provider and model consistency', function (): void {
    $openai = (new AssistantAgent)->resolve('x');
    $anthropic = (new AssistantAgent)->resolve('y', provider: 'anthropic');

    expect(fn () => Batch::of(['a' => $openai, 'b' => $anthropic])->submit())
        ->toThrow(BatchException::class, 'same provider');

    expect(fn () => Batch::of(['a' => $openai])->submit(model: 'gpt-5.4-nano'))
        ->toThrow(BatchException::class, 'requires [gpt-5.4-nano]');

    expect(fn () => Batch::of()->submit())
        ->toThrow(BatchException::class, 'empty batch');
});

test('results fetched in another process keep invocation ids and structured flags through the store', function (): void {
    Event::fake([BatchRequestCompleted::class, AgentPrompted::class]);

    Http::fake([
        'api.openai.com/v1/files' => Http::response(['id' => 'file-in']),
        'api.openai.com/v1/batches' => Http::response(openAiBatch()),
        'api.openai.com/v1/batches/batch_abc' => Http::response(openAiBatch('completed', ['output_file_id' => 'file-out'])),
        'api.openai.com/v1/files/file-out/content' => Http::response(openAiOutputLine('a', 'Plain')."\n".openAiOutputLine('b', '{"sentiment":"negative"}')."\n"),
    ]);

    $requests = [
        'a' => (new AssistantAgent)->resolve('Plain please'),
        'b' => (new StructuredAgent)->resolve('This is awful'),
    ];

    Batch::of($requests)->submit();

    expect(DB::table('ai_batch_requests')->where('batch_id', 'batch_abc')->count())->toBe(2)
        ->and(DB::table('ai_batch_requests')->where('custom_id', 'b')->value('structured'))->toBeTruthy()
        ->and(DB::table('ai_batch_requests')->where('custom_id', 'b')->value('agent'))->toBe(StructuredAgent::class);

    // A fresh handle, as PollBatch or a scheduler would build it, knows nothing in-process.
    $results = Batch::find('batch_abc', 'openai')->results();

    expect($results['a']->invocationId)->toBe($requests['a']->invocationId)
        ->and($results['b'])->toBeInstanceOf(StructuredAgentResponse::class)
        ->and($results['b']->invocationId)->toBe($requests['b']->invocationId);

    Event::assertDispatched(BatchRequestCompleted::class, fn (BatchRequestCompleted $event): bool => $event->customId === 'b'
        && $event->invocationId === $requests['b']->invocationId);
    Event::assertNotDispatched(AgentPrompted::class);

    Batch::forget(Batch::find('batch_abc', 'openai'));

    expect(DB::table('ai_batch_requests')->count())->toBe(0);
});

test('every provider status maps onto the enum and results availability follows the file ids', function (): void {
    Http::fake([
        'api.openai.com/v1/batches/batch_abc' => Http::sequence()
            ->push(openAiBatch('failed', ['error_file_id' => 'file-err']))
            ->push(openAiBatch('expired', ['output_file_id' => 'file-out']))
            ->push(openAiBatch('cancelled', ['output_file_id' => 'file-out']))
            ->push(openAiBatch('cancelling'))
            ->push(openAiBatch('finalizing'))
            ->push(openAiBatch('something_new')),
    ]);

    $find = fn () => Batch::find('batch_abc', 'openai');

    $failed = $find();
    expect($failed->status)->toBe(BatchStatus::Failed)->and($failed->hasFailed())->toBeTrue()->and($failed->hasResults())->toBeTrue();
    $expired = $find();
    expect($expired->status)->toBe(BatchStatus::Expired)->and($expired->hasResults())->toBeTrue();
    expect($find()->status)->toBe(BatchStatus::Cancelled);
    $cancelling = $find();
    expect($cancelling->status)->toBe(BatchStatus::Cancelling)->and($cancelling->isFinished())->toBeFalse()->and($cancelling->hasResults())->toBeFalse();
    expect($find()->status)->toBe(BatchStatus::Finalizing);
    expect($find()->status)->toBe(BatchStatus::Validating);
});

test('reading results before they exist throws instead of returning an empty collection', function (): void {
    Http::fake(['api.openai.com/v1/batches/batch_abc' => Http::response(openAiBatch('in_progress'))]);

    Batch::find('batch_abc', 'openai')->results();
})->throws(BatchNotReadyException::class, 'status: in_progress');

test('an expired batch still yields the results that did complete', function (): void {
    Http::fake([
        'api.openai.com/v1/batches/batch_abc' => Http::response(openAiBatch('expired', ['output_file_id' => 'file-out', 'request_counts' => ['total' => 2, 'completed' => 1, 'failed' => 0]])),
        'api.openai.com/v1/files/file-out/content' => Http::response(openAiOutputLine('post-1', 'Made it')."\n"),
    ]);

    $batch = Batch::find('batch_abc', 'openai');

    expect($batch->status)->toBe(BatchStatus::Expired)
        ->and($batch->hasResults())->toBeTrue()
        ->and($batch->results()['post-1']->text)->toBe('Made it');
});

test('result files are read as a stream across chunk boundaries', function (): void {
    $lines = '';
    for ($i = 1; $i <= 40; $i++) {
        $lines .= openAiOutputLine("r{$i}", str_repeat('x', 5000))."\n";
    }

    Http::fake([
        'api.openai.com/v1/batches/batch_abc' => Http::response(openAiBatch('completed', ['output_file_id' => 'file-out'])),
        'api.openai.com/v1/files/file-out/content' => Http::response($lines),
    ]);

    $seen = [];

    Batch::find('batch_abc', 'openai')->each(function ($result, string $customId) use (&$seen): void {
        $seen[$customId] = strlen($result->text);
    });

    expect($seen)->toHaveCount(40)
        ->and($seen['r1'])->toBe(5000)
        ->and($seen['r40'])->toBe(5000)
        ->and(strlen($lines))->toBeGreaterThan(65536);
});

test('a truncated result line becomes a failure instead of disappearing', function (): void {
    $good = openAiOutputLine('ok', 'fine');
    $truncated = substr(openAiOutputLine('cut', 'lost'), 0, 120);

    Http::fake([
        'api.openai.com/v1/batches/batch_abc' => Http::response(openAiBatch('completed', ['output_file_id' => 'file-out'])),
        'api.openai.com/v1/files/file-out/content' => Http::response($good."\n".$truncated),
    ]);

    $results = Batch::find('batch_abc', 'openai')->results();

    expect($results)->toHaveCount(2)
        ->and($results['ok']->text)->toBe('fine')
        ->and($results['cut'])->toBeInstanceOf(BatchRequestFailed::class)
        ->and($results['cut']->code)->toBe('invalid_line')
        ->and($results->hasFailures())->toBeTrue();
});

test('garbage without a custom id aborts the read loudly', function (): void {
    Http::fake([
        'api.openai.com/v1/batches/batch_abc' => Http::response(openAiBatch('completed', ['output_file_id' => 'file-out'])),
        'api.openai.com/v1/files/file-out/content' => Http::response("<html>proxy error</html>\n"),
    ]);

    Batch::find('batch_abc', 'openai')->results();
})->throws(BatchException::class, 'no custom id');

test('the batch input file goes through the SDK file provider so Files::fake applies', function (): void {
    Event::fake([FileStored::class]);
    Files::fake();

    Http::fake(['api.openai.com/v1/batches' => Http::response(openAiBatch())]);

    Batch::of(['post-1' => (new AssistantAgent)->resolve('First')])->submit();

    Files::assertStored(fn ($file): bool => $file->name() === 'batch.jsonl'
        && str_contains($file->content(), '"custom_id":"post-1"')
        && $file->providerOptions(Lab::OpenAI) === ['purpose' => 'batch']);

    Http::assertSentCount(1);
    Event::assertDispatched(FileStored::class);
});
