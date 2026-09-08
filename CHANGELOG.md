# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.1.0] - 2026-09-08

### Added

- `Resolvable::resolve()` returns the exact provider request an agent would send, captured through
  the SDK's own prompt path.
- `Batch::of()->submit()`, `Batch::find()`, `BatchHandle::results()` / `each()` / `cancel()` /
  `refresh()`, with results parsed into the SDK's `AgentResponse` and `StructuredAgentResponse`.
- OpenAI (`/v1/batches`), Anthropic (`/v1/messages/batches`) and OpenRouter (`/api/beta/batches`)
  gateways, plus the `BatchGateway` contract and `Batch::extend()` for others.
- A `BatchStore` (database by default, with migration; `array` optional) that keeps invocation ids
  and structured output flags between the submitting process and the one reading results.
- Queue polling through `then()` / `catch()` with a self-releasing `PollBatch` job, exponential
  poll delay, error backoff and a `failed()` hook.
- `BatchSubmitted`, `BatchRequestCompleted` and `BatchRequestErrored` events; the SDK's
  `PromptingAgent` fires at resolve time with the same invocation id.
- `Batch::fake()` with `assertSubmitted()`, `assertSubmittedTimes()` and `assertNothingSubmitted()`;
  `Agent::fake()` alone keeps batches off the network.

[0.1.0]: https://github.com/pietervanleuven/laravel-ai-batch/releases/tag/v0.1.0
