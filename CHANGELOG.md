# Changelog

## [0.2.0](https://github.com/pietervanleuven/laravel-ai-batch/compare/v0.1.1...v0.2.0) (2026-09-27)


### ⚠ BREAKING CHANGES

* support laravel/ai 1.0 ([#4](https://github.com/pietervanleuven/laravel-ai-batch/issues/4))

### Features

* support laravel/ai 1.0 ([#4](https://github.com/pietervanleuven/laravel-ai-batch/issues/4)) ([e540e90](https://github.com/pietervanleuven/laravel-ai-batch/commit/e540e909ca4dec2f1c4683ea1653fe205a4e6997))


### Bug Fixes

* **deps:** require laravel/ai ^0.11.1 ([57df2a3](https://github.com/pietervanleuven/laravel-ai-batch/commit/57df2a30445d3d1df603271d151f39d7f867a262))
* reject batch results with a missing or duplicate custom id ([4cd7863](https://github.com/pietervanleuven/laravel-ai-batch/commit/4cd786360b2424a426d1d170bc2d957cac4d45ac))
* resolve Larastan findings in the service provider and response builder ([ca6281f](https://github.com/pietervanleuven/laravel-ai-batch/commit/ca6281f0dd3c307af98ef15f07eabd7f666a51d4))

## [0.1.1] - 2026-09-08

### Added

- A prior art section in the README, recording what `refinephp/laravel-ai-batch` covers and how it differs
  (provider coverage, its exact `laravel/ai` pin, and whether results come back as SDK response objects).

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

[0.1.1]: https://github.com/pietervanleuven/laravel-ai-batch/releases/tag/v0.1.1
[0.1.0]: https://github.com/pietervanleuven/laravel-ai-batch/releases/tag/v0.1.0
