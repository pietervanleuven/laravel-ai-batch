# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Changed

- CI is split into separate workflows for tests, static analysis and code style, replacing the single
  `tests.yml`.
- Tests run against Laravel 12 and 13, with both lowest and stable dependencies, on Ubuntu and Windows.
- Static analysis uses Larastan at level 6 (`phpstan.neon.dist`, with a baseline), run as `composer analyse`.
- Code style is fixed automatically on push with Pint (Laravel preset), and checked on pull requests from forks;
  `composer format` runs it locally.

### Added

- Dependabot keeps GitHub Actions up to date, with patch and minor updates merged automatically.
- Publishing a GitHub release writes its notes into this changelog.
- Pull request titles are checked against Conventional Commits.
- Contributor docs: `CONTRIBUTORS.md`, `AGENTS.md` for AI coding agents, a security policy and a bug report
  template.

### Fixed

- `laravel/ai` is now required at `^0.11.1`: OpenRouter batch submission does not work with 0.11.0.
- The assistant message passed to `withMessages()` is typed as a `Collection<int, Message>`, as the SDK expects.
- A provider result without a usable custom id, or with a custom id that was already returned, now throws a
  `BatchException` instead of being stored under an empty key or overwriting an earlier result.

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
