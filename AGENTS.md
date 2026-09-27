# AGENTS.md

Guidance for AI coding agents (Claude Code, Codex, Cursor, …) working in this repository. Humans should read [CONTRIBUTORS.md](CONTRIBUTORS.md) as well.

## What this package is

A Laravel package that adds asynchronous batch API support to the [`laravel/ai`](https://github.com/laravel/ai) SDK. It does two things the SDK can't: it **resolves** the exact request body an agent would send, without sending it, and it runs the **batch lifecycle** (submit, poll, cancel, read results) against the OpenAI, Anthropic and OpenRouter batch APIs. Results come back as the SDK's own `AgentResponse` / `StructuredAgentResponse` objects. Nothing in the SDK is modified.

Layout follows the usual package shape: `src/`, `config/`, `database/migrations/`, `tests/` (Pest + Orchestra Testbench), `.github/workflows/`.

## Commands

```bash
composer install
composer test                   # Pest, all tests (must stay green)
vendor/bin/pest --filter=Anthropic
composer analyse                # PHPStan / Larastan level 6, must report no errors
composer format                 # code style (Pint, Laravel preset)
```

Run all three before you consider a change done.

## Architecture in one paragraph

`BatchManager` (behind the `Batch` facade) is the entry point: it picks a `BatchGateway` for a `TextProvider` (`Batch::fake()` first, then a gateway swapped onto the provider with `useTextGateway()`, then an `Agent::fake()` gateway, then the `gateways` map in `config/ai-batch.php`, keyed by **driver**), and owns the `BatchStore`. `Resolver` (used by the `Resolvable` trait) runs the SDK's own prompt path against a `CapturingTextGateway` that throws `RequestCaptured` at the first generation step, producing a `ResolvedRequest`. `PendingBatch` collects resolved requests and submits them; the result is a provider-agnostic `BatchHandle`. Results are a `BatchResults` collection of responses or `BatchRequestFailed` values. `PollBatch` is a self-releasing queue job that runs `then()` / `catch()` callbacks once a batch is terminal.

```
src/
├── AiBatchServiceProvider.php       # binds BatchStore, BatchManager, Resolver; publishes config + migration
├── Batch.php                        # facade for BatchManager
├── BatchManager.php                 # gateway selection, submit/retrieve/cancel/results, fakes + assertions
├── PendingBatch.php                 # Batch::of()->add()->submit()
├── BatchHandle.php                  # provider-agnostic batch: status, counts, results(), each(), then()/catch()
├── BatchResults.php                 # collection keyed by custom id
├── BatchRequestFailed.php           # + BatchRequestFailureType (Errored, Canceled, Expired, Invalid)
├── BatchStatus.php  BatchRequestCounts.php
├── Resolvable.php                   # trait: $agent->resolve(...)
├── PendingBatchPoll.php             # dispatches PollBatch when it goes out of scope
├── Contracts/                       # BatchGateway, BatchStore, ResolvesTextRequests
├── Gateway/
│   ├── OpenAi/ Anthropic/ OpenRouter/   # one gateway per driver, each extending that driver's SDK gateway
│   ├── Concerns/                    # BuildsAgentResponses, ParsesJsonLines, NarrowsProviders, ResolvesRequestContext
│   ├── CapturingTextGateway.php     # used by Resolver
│   └── FakeBatchGateway.php         # Batch::fake() and the Agent::fake() path
├── Requests/                        # Resolver, ResolvedRequest, RequestContext
├── Storage/                         # DatabaseBatchStore (default), ArrayBatchStore
├── Jobs/PollBatch.php
├── Events/                          # BatchSubmitted, BatchRequestCompleted, BatchRequestErrored
└── Exceptions/
```

## How laravel/ai is reached (fragile spots)

`laravel/ai` has no public "resolve a request" API (laravel/ai #59, #767), so these spots depend on its internals. Re-check them when bumping `laravel/ai`:

- **Batch gateways subclass the SDK's gateway for their driver** (plain inheritance), so its protected body builders and response parsers are reachable. The SDK gateways don't inherit from one another: `OpenAiBatchGateway` batches `/v1/responses` bodies, while OpenAI-shaped drivers such as `openrouter`, `azure`, `groq` and `xai` build *chat completions* bodies. A new gateway extends *its own driver's* SDK gateway; `OpenRouterBatchGateway` is the worked example.
- **Provider, model and timeout precedence** live in `protected` `Promptable` helpers. `Resolver::callProtected()` is the only place that reaches them. Keep it that way.
- **Request capture** goes through the SDK's public `useTextGateway()` seam on a cloned provider, the same one `Agent::fake()` uses.

If `laravel/ai` changes any of these, fix it in the gateway or in `Resolver`, and nowhere else.

## Rules

- **No second mapping layer.** Request bodies and result parsing go through the SDK's own gateway code. Don't hand-build provider bodies or hand-map responses into `AgentResponse`.
- **One driver, one gateway.** A new provider is a `BatchGateway` implementation under `src/Gateway/{Provider}/`, registered in the `gateways` map of `config/ai-batch.php` (or via `Batch::extend()` in user land). Provider-specific logic stays in that gateway.
- **A bad result is a value, not an exception.** A failed or undecodable result becomes a `BatchRequestFailed` with the right `BatchRequestFailureType` (`Invalid` for a line that can't be decoded), so partially failed batches stay consumable. Reading results before the provider has them throws `BatchNotReadyException`; a provider returning the same custom id twice throws a `BatchException`. Don't add other throws to the results path.
- **Don't invent upstream capabilities.** If a provider doesn't offer an operation (OpenRouter has no cancel endpoint, and no partial results), the gateway throws a `BatchException`, and the README's provider notes say so. Every provider status maps onto `BatchStatus`.
- **The batch store keeps context, not content.** It holds custom id, invocation id, structured flag, agent class and model, nothing else. Never store prompts, response bodies or credentials in it.
- **Keep credentials out of serialized state.** `PollBatch` carries the batch id and the provider *name*, and resolves the provider again on the worker. Don't put a `TextProvider`, a config array or a key on a job, event or handle payload, and don't add keys to `BatchHandle::toArray()`.
- **Callbacks are serialized closures.** `then()` / `catch()` wrap closures in `Laravel\SerializableClosure\SerializableClosure`. Don't add another place that unserializes closures or classes from provider data.
- **Streaming stays streaming.** OpenAI and Anthropic results are read as a JSONL stream (`ParsesJsonLines`). Don't buffer a whole results file into memory.
- **Every request in a batch is resolved for the same provider.** `PendingBatch` / `BatchManager` validate that (and a single model when asked, or always for OpenRouter) before anything is sent.
- Agents using `RemembersConversations` are rejected by `Resolver`; batch results never go back to a conversation store.
- Match the surrounding style: no `declare(strict_types=1)`, typed properties, constructor promotion, short docblocks only where they add information.

## Tests

- `tests/TestCase.php` boots Testbench with `AiServiceProvider` and `AiBatchServiceProvider`, configures `openai`, `anthropic` and `openrouter` providers with the key `test-key`, loads the package migration, and calls **`Http::preventStrayRequests()`**. No test may reach a real provider.
- Gateway tests (`OpenAiBatchTest`, `AnthropicBatchTest`, `OpenRouterBatchTest`) fake the vendor endpoints with `Http::fake([...])` using realistic payloads taken from the provider docs, and assert on the outgoing request with `Http::assertSent()` (URL, auth header, body shape). Follow that pattern rather than mocking the gateway.
- The OpenAI input file goes through the SDK's file provider, so `Files::fake()` / `Files::assertStored()` can inspect the JSONL.
- `Batch::fake()` (and `FakeBatchGateway::setStatus()`) covers lifecycle and polling tests; `Queue::fake()` asserts that `PollBatch` is dispatched. `Agent::fake()` on its own must also keep batches off the network.
- Fixture agents live in `tests/Fixtures/Agents` (`AssistantAgent`, `AnthropicAgent`, `OpenRouterAgent`, `StructuredAgent`, `ConversationalAgent`, `RememberingAgent`). Add a fixture rather than defining agents inline.
- A new gateway needs tests for submit, retrieve, cancel (or the documented refusal), results (success, error, and a line that can't be decoded), status mapping, and structured output detection.

## Documentation

User-facing behaviour changes need a README update. Don't edit `CHANGELOG.md`: release-please generates it from Conventional Commit messages (`feat:`, `fix:`, `feat!:` for breaking changes), so write the commit or PR title with that in mind. When a gateway changes because a provider changed, re-read the vendor docs listed under *Provider API references* in the README and update the *Last checked* date. Keep README examples truthful: they must match what the code and tests actually do.
