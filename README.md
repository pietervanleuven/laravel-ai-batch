# Laravel AI Batch

Asynchronous batch API support for [laravel/ai](https://github.com/laravel/ai), shipped as a separate package.
OpenAI (`/v1/batches`), Anthropic (`/v1/messages/batches`) and OpenRouter (`/api/beta/batches`) are supported
today; other providers plug in through one contract.

Batch APIs run the same request at roughly half the per-token price in exchange for a 24-hour completion window.
The SDK has no way to use them and, more importantly, no way to obtain the request body it would have sent
(laravel/ai #59, #767). This package adds both:

1. **Resolved requests**: get the exact body the SDK would POST, without sending it.
2. **Batch lifecycle**: submit resolved requests, poll, and turn results back into the same
   `AgentResponse` / `StructuredAgentResponse` objects the synchronous path produces.

Nothing in the SDK is modified. Request bodies and result parsing go through the SDK's own gateway code, so
there is no second mapping layer to drift.

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- `laravel/ai` ^0.11

## Installation

```bash
composer require prvn/laravel-ai-batch
```

The service provider is auto-discovered. Run the migration, which creates the `ai_batch_requests` table
that keeps per-request context (invocation id, structured output flag, agent) between the process that
submits a batch and the one that reads its results:

```bash
php artisan migrate
```

Optionally publish the config and the migration:

```bash
php artisan vendor:publish --tag=ai-batch-config
php artisan vendor:publish --tag=ai-batch-migrations
```

Set `AI_BATCH_STORE=array` to skip the table; results read in another process then lose invocation ids
and need `structured:` passed explicitly (see below).

## Resolving requests

Add the `Resolvable` trait to any agent:

```php
use AiBatch\Resolvable;
use Laravel\Ai\Promptable;

class AnalysisAgent implements Agent, HasStructuredOutput
{
    use Promptable, Resolvable;
    // ...
}
```

```php
$request = (new AnalysisAgent)->resolve('Summarise this week', provider: Lab::Anthropic);

$request->provider;   // TextProvider
$request->model;      // 'claude-sonnet-5'
$request->endpoint;   // '/v1/messages'
$request->body;       // array, exactly what the SDK would POST
$request->schema;     // array|null, the structured output schema
$request->toBatchLine('post-1'); // OpenAI-style JSONL line
```

`resolve()` accepts the same `attachments`, `provider`, `model` and `timeout` arguments as `prompt()`. It runs
the SDK's own prompt path (middleware, conversation history, tool resolution, structured output, provider
options, abandoned tool call settlement) against a gateway that captures the first generation step instead of
sending it, so the body is byte for byte what `prompt()` would send. The SDK's `PromptingAgent` and
`StartingStep` events fire as they would for a real prompt; `AgentPrompted` and `AgentFailed` do not.

Agents using `RemembersConversations` are rejected, because batch results are never written back to the
conversation store.

Agents without the trait can be resolved through the resolver:

```php
app(\AiBatch\Requests\Resolver::class)->resolve($agent, 'prompt');
```

## Submitting a batch

```php
use AiBatch\Batch;

$batch = Batch::of([
        'post-1' => (new AnalysisAgent)->resolve($post1->text),
        'post-2' => (new AnalysisAgent)->resolve($post2->text),
    ])
    ->submit();

$batch->id;         // provider batch id, store it
$batch->status;     // BatchStatus::Validating
$batch->expiresAt;  // CarbonImmutable|null
```

`Batch::of()` also accepts agents directly through `add()`:

```php
Batch::of()
    ->add('post-1', new AnalysisAgent, $post1->text)
    ->add('post-2', new AnalysisAgent, $post2->text)
    ->submit(options: ['metadata' => ['job' => 'nightly']]);
```

Every request in a batch must be resolved for the same provider. Pass `model:` to `submit()` to additionally
require a single model.

## Reading results

```php
$batch = Batch::find($id, provider: Lab::OpenAI);

if ($batch->isFinished()) {
    foreach ($batch->results() as $customId => $result) {
        // $result is AgentResponse | StructuredAgentResponse | BatchRequestFailed
    }
}
```

`results()` returns a `BatchResults` collection keyed by custom id. A request that failed yields a
`BatchRequestFailed` instead of throwing, so partially failed batches stay consumable. Its `type` is a
`BatchRequestFailureType` (`Errored`, `Canceled`, `Expired`, or `Invalid` for a result line that could not be
decoded); `neverRan()` tells unbilled requests apart from real errors. Helpers: `successful()`, `failed()`, `hasFailures()`, `throw()`.

Use `hasResults()` rather than `isCompleted()` to decide whether there is anything to read: a cancelled or
expired batch still returns the requests that ran before it stopped. Reading results before the provider has
them throws `BatchNotReadyException`.

For large batches, stream results instead of materialising them:

```php
$batch->each(function (AgentResponse|BatchRequestFailed $result, string $customId) {
    // ...
});
```

Each result keeps the invocation id assigned at `resolve()` time and is decoded as structured output when
the original request was, whether it is read in the submitting process or later through the batch store.
The `structured:` argument overrides that when you know better, for example with the array store:

```php
Batch::find($id, 'anthropic')->results(structured: true);
```

Once an application is done with a batch it can drop its stored context with `Batch::forget($batch)`.

## Polling on the queue

```php
Batch::of($requests)
    ->submit()
    ->then(fn (BatchResults $results, BatchHandle $batch) => ...)
    ->catch(fn (?BatchHandle $batch, ?Throwable $exception) => ...)
    ->every(120, max: 1800)   // first delay and cap; defaults from config('ai-batch.poll_interval' / 'poll_max_interval')
    ->onQueue('ai');
```

The delay doubles after each check up to the cap, so a 24-hour batch is not polled every minute for a day.

This dispatches one `PollBatch` job per handle (calling `then()` and `catch()` as separate statements shares
it). The job re-releases itself until the batch is terminal, then runs `then` whenever the provider has
results to read, including the partial results of a cancelled or expired batch, and `catch` when it ended
with nothing to read. Provider errors while polling retry after a full interval; after `maxExceptions`
errors or `poll_timeout_hours`, the job fails and `catch` receives the exception.

The job is dispatched when the pending poll goes out of scope, like the SDK's `queue()`. If you keep the
handle alive on a long-lived object, dispatch explicitly with `$batch->poll()->dispatch()`. Polling needs a
real queue driver (the `sync` driver cannot release). Applications with their own scheduler can ignore it and
call `Batch::find()`.

## Events

- `AiBatch\Events\BatchSubmitted` (handle, requests)
- `AiBatch\Events\BatchRequestCompleted` (invocationId, customId, handle, response), once per successful result
- `AiBatch\Events\BatchRequestErrored` (invocationId, customId, handle, failure)
- The SDK's `PromptingAgent` fires per request at `resolve()` time. `BatchRequestCompleted` carries the same
  invocation id in every process, through the batch store, so listeners pairing the two keep working.
  `AgentPrompted` additionally fires per result when results are consumed in the submitting process, where
  the original `AgentPrompt` is still available.

## Testing

```php
Batch::fake();                       // batches complete immediately, nothing is sent
Batch::fake(['post-1' => 'text']);   // responses keyed by custom id (string, array => structured, closure,
                                     // TextResponse, or BatchRequestFailed to simulate a failure)

Batch::assertSubmitted(fn (array $requests, TextProvider $provider, array $options) => ...);
Batch::assertSubmittedTimes(2);
Batch::assertNothingSubmitted();
```

The OpenAI input file is uploaded through the SDK's file provider, so `Files::fake()` intercepts it and
`Files::assertStored()` can inspect the JSONL.

`Agent::fake([...])` on its own is enough to keep batches off the network: `resolve()` still builds the real
provider body, but `submit()` and `results()` go through an in-memory gateway and results come from the
agent's fake responses in submission order. `Agent::assertPrompted()` works on resolved requests. Use
`Batch::fake()` when you need the batch-level assertions or per-custom-id responses.

## Provider coverage

`laravel/ai` ships sixteen drivers. Batching only makes sense for the ones that implement
`Laravel\Ai\Contracts\Providers\TextProvider` *and* whose vendor exposes an asynchronous batch API.
`Batch` picks the gateway from the provider: `Batch::fake()` first, then a `BatchGateway` swapped onto the
provider with `useTextGateway()`, then an `Agent::fake()` gateway, and finally the `gateways` map in
`config/ai-batch.php`, which is keyed by **driver** name like the table below.

| Driver | Text provider | Vendor batch API | Status here | Work needed |
|---|---|---|---|---|
| `openai` | yes | JSONL upload + `POST /v1/batches` | **supported** | — |
| `anthropic` | yes | `POST /v1/messages/batches` (inline requests) | **supported** | — |
| `azure` | yes | same shape as OpenAI, against a Global-Batch / Data-Zone-Batch deployment | not shipped | small: same file+batch flow, but the SDK's Azure gateway builds *chat completions* bodies, so the endpoint is `/chat/completions` and the batch line must carry the deployment/`api-version` in the URL |
| `gemini` | yes | Batch Mode (`batches.create`, inline requests or a JSONL file in Files) | not shipped | medium: different job shape (long-running operation, `inlinedResponses` vs. a result file), no `custom_id` on the inline path — keys have to be carried alongside |
| `mistral` | yes | `POST /v1/batch/jobs` over uploaded JSONL | not shipped | small–medium: OpenAI-ish, but one model per job, `input_files[]`, `timeout_hours`, and its own status vocabulary |
| `groq` | yes | OpenAI-compatible `POST /openai/v1/batches` against `/v1/chat/completions` | not shipped | small: closest thing to a drop-in; mostly the chat-completions body builder and result parser |
| `xai` | yes | OpenAI-compatible Batch API (Files JSONL + batches) | not shipped | small: same as Groq, over the xAI gateway |
| `openrouter` | yes | `POST /api/beta/batches` (inline requests, inline results) | **supported** | — |
| `bedrock` | yes | batch inference jobs (`CreateModelInvocationJob`, S3 in/out) | not shipped | large: no HTTP file API — records go to S3, results come back as S3 objects, and submission is SigV4-signed |
| `openai-compatible` | yes | depends entirely on the upstream server | not shipped | can't be answered generically; register a gateway per deployment if that server implements `/v1/batches` |
| `deepseek` | yes | none published on DeepSeek's own platform (off-peak discounts instead; batch exists on resellers) | out of scope | would need a vendor batch API first |
| `ollama` | yes | none (local runtime) | out of scope | nothing to batch against — loop synchronously |
| `cohere` | no (embeddings + reranking) | — | out of scope | — |
| `voyageai` | no (embeddings + reranking) | — | out of scope | — |
| `jina` | no (embeddings + reranking) | — | out of scope | — |
| `eleven` | no (audio + transcription) | — | out of scope | — |

Two things make "extra work" larger or smaller than it looks:

- **Body shape.** `OpenAiBatchGateway` batches `/v1/responses` bodies. Every other OpenAI-shaped driver
  (`azure`, `groq`, `xai`, `openai-compatible`, `deepseek`) builds *chat completions* bodies through its own
  `BuildsTextRequests` concern, and the SDK gateways do not inherit from one another. A new gateway therefore
  extends *that driver's* SDK gateway, not `OpenAiBatchGateway` — `OpenRouterBatchGateway` is the worked example.
- **Result identity.** The package keys results by `custom_id`. Providers that don't echo a caller-supplied id
  (Gemini's inline path, Bedrock's S3 records) need the mapping reconstructed from request order.

Non-text batch surfaces — embeddings (Mistral, Gemini, Bedrock), image and video (xAI), audio transcription
(Groq) — are outside this package: `BatchGateway` is typed against `TextProvider`.

## Adding a provider

Implement `AiBatch\Contracts\BatchGateway` (submit, retrieve, results, cancel, plus the request-body resolver),
usually by extending the SDK gateway for that driver so its protected builders and parsers are reachable,
then register it:

```php
// config/ai-batch.php
'gateways' => [
    'openai' => OpenAiBatchGateway::class,
    'anthropic' => AnthropicBatchGateway::class,
    'mistral' => App\Ai\MistralBatchGateway::class,
],

// or at runtime
Batch::extend('mistral', fn ($app) => new MistralBatchGateway($app['events']));
```

## Provider notes

| Provider | Transport | Cancel | Structured output detection |
|---|---|---|---|
| OpenAI | JSONL file (`purpose=batch`) + `POST /v1/batches` against `/v1/responses` | yes | from `text.format` in the response |
| Anthropic | inline `requests[]` + results JSONL stream | yes | from the batch store; the response itself only reveals tool-based schemas, so pass `structured: true` for native `output_config` with the array store |
| OpenRouter | inline `requests[]` + inline `results[]` on the batch object | no — not offered upstream | from the batch store; a chat completion never echoes its `response_format`, so pass `structured: true` with the array store |

OpenRouter specifics:

- The batch API lives under `https://openrouter.ai/api/beta`, not the `/api/v1` base the SDK client uses; the
  gateway swaps the suffix, and a custom `ai.providers.*.url` that does not end in `/v1` is used as-is.
- The endpoint and model are carried once, at the batch level, so **every request in a batch must resolve to
  the same model** — mixed models throw a `BatchException` before anything is sent. The model is stripped from
  each request body on the way out.
- The submit payload is serialized with `endpoint` and `model` before `requests`, which OpenRouter's
  stream-parser requires.
- Results come back inline on `GET /api/beta/batches/{id}` once the batch completes, so `results()` re-fetches
  the batch rather than downloading a file, and there is nothing to stream. OpenRouter deletes inputs and
  results 30 days after creation.
- **`results` is `null` for anything but a completed batch.** Unlike the file-based providers, a batch that was
  cancelled or expired part way through surfaces no partial results, so `results()` throws
  `BatchNotReadyException` rather than yielding the requests that did finish.
- Batches are text-only upstream (no image, audio, video, or file parts) and the completion window is fixed
  at 24h.
- **`cancel()` throws.** OpenRouter's API documents only submit, list and retrieve; there is no cancel
  endpoint, even though a batch can reach `cancelling` / `cancelled` by other means (the dashboard). Those
  statuses are still mapped when they are observed.
- A terminal batch carries `finalized_at`, which becomes `endedAt`; there is no expiry field, so `expiresAt`
  is always null.

Tool calls inside a batch are returned on the response (`toolCalls`) but not executed, since a batch cannot
continue the conversation. Multi-step agents should be run synchronously.

## Provider API references

Each gateway is written against the vendor's own documentation rather than a client library, so these are the
pages to re-read when a provider changes something. Last checked 2026-09-08.

| Provider | Reference |
|---|---|
| OpenAI | [Batch API guide](https://platform.openai.com/docs/guides/batch) and [`/v1/batches` reference](https://platform.openai.com/docs/api-reference/batch) |
| Anthropic | [Message Batches guide](https://docs.claude.com/en/docs/build-with-claude/batch-processing) and [`/v1/messages/batches` reference](https://docs.claude.com/en/api/creating-message-batches) |
| OpenRouter | [Batch API quickstart](https://openrouter.ai/docs/batch-quickstart); the complete docs corpus at [`openrouter.ai/docs/llms-full.txt`](https://openrouter.ai/docs/llms-full.txt) is the authority for what the batch surface does and does not offer, since the published [OpenAPI spec](https://openrouter.ai/openapi.json) covers `/api/v1` only and omits the beta batch endpoints entirely |

Two OpenRouter behaviours above — no cancel endpoint, and no partial results — were settled by searching that
corpus rather than by a live call, so they reflect what OpenRouter documents, not what its servers were
observed doing.

## How it reaches SDK internals

Request bodies come from the SDK's own gateways: the batch gateways here subclass them (plain inheritance) and
`Resolver` installs a capturing step gateway on a cloned provider through the SDK's public `useTextGateway()`
seam, the same one `Agent::fake()` uses. Only provider, model and timeout precedence live in `protected`
Promptable helpers; `Resolver::callProtected()` is the single place that reaches them, and the only thing to
touch when the SDK ships a public `resolve()` hook (#767).
