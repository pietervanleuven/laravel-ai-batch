# Security Policy

If you discover a security issue, please email pieter.van.leuven@gmail.com instead of opening a public issue. All security vulnerabilities will be addressed promptly.

Areas that deserve extra care:

- **Provider API keys.** Keys are read from the `laravel/ai` provider config and sent by the batch gateways (`src/Gateway/`). They must never end up in a queued job, an event, a `BatchHandle`, the batch store, an exception message, or a request sent to a host other than the configured provider URL (see how `OpenRouterBatchGateway` derives its batch base URL).
- **Queued polling callbacks.** `then()` / `catch()` closures are serialized with `laravel/serializable-closure` into the `PollBatch` job payload (`src/Jobs/PollBatch.php`) and executed on the queue worker.
- **Stored request context.** The batch store (`src/Storage/`) keeps only custom ids, invocation ids, structured output flags, agent class names and models. Anything that would put prompts, responses or credentials into it is a security concern.
- **Parsing provider output.** Result files and inline results (`src/Gateway/Concerns/ParsesJsonLines.php` and each gateway's result parser) are untrusted input and must be decoded as data only.

Prompts and responses themselves go to the provider you configured and are subject to that provider's retention policy (for example, the OpenAI input file uploaded with `purpose=batch`). That is not something this package can change.
