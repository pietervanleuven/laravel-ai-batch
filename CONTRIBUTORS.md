# Contributing

Contributions are welcome and will be fully credited. Provider batch APIs change without much notice, so issues that point at an upstream change (a new field, a new status, an endpoint that moved) are as valuable as code.

Please read and understand this guide before creating an issue or pull request. If you're an AI coding agent, also read [AGENTS.md](AGENTS.md).

## Etiquette

This project is open source, and as such, the maintainers give their free time to build and maintain it. The code is freely available in the hope that it will be useful. It would be extremely unfair for someone to use this package and then complain or be abusive toward the maintainers.

Please be considerate when raising issues or presenting pull requests. Let's show the world that developers are civilized and selfless people.

The maintainers decide whether a contribution meets the quality standard and fits the project's direction.

## Viability

When requesting or submitting new features, first consider whether they are useful to others. Open source projects are used by many developers with different needs. Is the feature likely to be used by others?

**New providers** are welcome as gateways. The README's *Provider coverage* table lists which `laravel/ai` drivers have a vendor batch API and roughly how much work each would take. Please link the vendor's batch API documentation, and say whether the API is generally available or in beta.

This package deliberately has no second mapping layer: request bodies and result parsing go through the SDK's own gateway code. Features that would need to hand-build provider bodies are unlikely to be accepted; the fix for those usually belongs in `laravel/ai` itself.

## Development setup

```bash
git clone git@github.com:pietervanleuven/laravel-ai-batch.git
cd laravel-ai-batch
composer install
composer test
```

You need PHP 8.3+ with `pdo_sqlite`. No API keys, database server or Laravel app are needed: the test suite boots a Testbench app with fake provider keys, runs the package migration on SQLite, and blocks every real HTTP request (`Http::preventStrayRequests()`). Provider endpoints are faked with `Http::fake()`.

| Command | What it runs |
| --- | --- |
| `composer test` | Pest |
| `composer test-coverage` | Pest with a coverage report (needs Xdebug or PCOV) |
| `composer analyse` | PHPStan / Larastan, level 6 |
| `composer format` | Laravel Pint |

### Trying it in a real Laravel app

Point a local app at your checkout with a Composer path repository:

```jsonc
// your-app/composer.json
"repositories": [
    { "type": "path", "url": "../laravel-ai-batch", "options": { "symlink": true } }
],
```

```bash
cd your-app
composer require laravel/ai pietervanleuven/laravel-ai-batch:@dev
php artisan migrate
```

Add the `AiBatch\Resolvable` trait to an agent, then check what it would send without calling the provider:

```bash
php artisan tinker
>>> (new App\Ai\Agents\MyAgent)->resolve('Hello')->body
```

To try a real batch, set a provider key in `.env` and submit a small one. Batches are billed, even at the batch discount, so keep test batches to a request or two:

```php
$batch = AiBatch\Batch::of(['hello' => (new MyAgent)->resolve('Hello')])->submit();

// later, possibly minutes or hours
AiBatch\Batch::find($batch->id, $batch->provider)->results();
```

To exercise `then()` / `catch()`, run a real queue worker (`php artisan queue:work`); the `sync` driver can't release the polling job.

## Procedure

Before filing an issue:

- Try to replicate the problem, to make sure it wasn't a coincidence.
- Check whether your feature suggestion has already been discussed in the project.
- Check the pull requests, to make sure the feature or fix isn't already in progress.
- Include your `config/ai-batch.php` (if published), the provider, and the batch id and status if the problem is with a submitted batch. **Remove API keys and any prompt or response content you can't share.**

Before submitting a pull request:

- Check the codebase, to make sure the feature doesn't already exist.
- Check the pull requests, to make sure another contributor hasn't already made the feature or fix.

## Requirements

- **[PSR-12 / Laravel Pint](https://laravel.com/docs/pint)**: run `composer format`. CI also fixes style automatically on push.
- **Add tests.** Your patch won't be accepted without them. Tests must never reach a real provider: fake the vendor endpoints with `Http::fake()` and assert on what was sent. Gateway changes need a test for the failure path (errored, expired or undecodable result lines) as well as the happy path.
- **PHPStan stays clean.** Don't add baseline entries for new code.
- **Document any change in behaviour.** Keep `README.md` up to date. The changelog is generated from commit messages, so don't edit `CHANGELOG.md` by hand. When a gateway changes because a provider changed, update the *Last checked* date under *Provider API references* in the README.
- **Consider the release cycle.** We follow [SemVer v2.0.0](https://semver.org/). Don't break public APIs at random. While the package is 0.x, breaking changes go in minor versions; mark them with `!` (`feat!: …`) so the changelog calls them out.
- **One pull request per feature.** If you want to do more than one thing, send multiple pull requests.
- **Use [Conventional Commits](https://www.conventionalcommits.org).** Commit messages and PR titles start with a type:
  `feat` (new capability, minor), `fix` (bug, patch), `feat!` / `fix!` (breaking, minor while below 1.0), or `docs`,
  `test`, `ci`, `build`, `refactor`, `chore`. Use the provider as the scope where it fits: `fix(anthropic): …`.
  PRs are squash-merged with the PR title as the commit message, so a check rejects titles that don't follow the format.

## Releases

[release-please](https://github.com/googleapis/release-please) keeps a release PR open. It holds the next version and the `CHANGELOG.md` entry, both built from the Conventional Commits on `main`. Merging it tags the release and publishes the GitHub release, which Packagist picks up. Don't edit the version or the changelog by hand.

## Adding a provider (checklist)

1. Add `src/Gateway/{Provider}/{Provider}BatchGateway.php`. Extend the SDK's gateway **for that driver** (not `OpenAiBatchGateway`), so its protected body builders and response parsers are reachable, and implement `AiBatch\Contracts\BatchGateway` (which includes the `ResolvesTextRequests` request-body resolver). `OpenRouterBatchGateway` is the worked example.
2. Map every provider status onto `BatchStatus`, and every failed or undecodable result onto `BatchRequestFailed`. If the provider doesn't offer an operation (such as cancel), throw a `BatchException` that says so.
3. Register it under the driver name in the `gateways` map of `config/ai-batch.php`.
4. Add a fixture agent in `tests/Fixtures/Agents`, a provider in `TestCase::defineEnvironment()`, and a `tests/Feature/{Provider}BatchTest.php` that fakes the vendor endpoints with `Http::fake()`.
5. Update the README: the *Provider coverage* table, a row in *Provider notes*, and a link under *Provider API references*.

## Contributors

- [Pieter Van Leuven](https://github.com/pietervanleuven): creator and maintainer
- [All contributors](https://github.com/pietervanleuven/laravel-ai-batch/graphs/contributors)

Add yourself to this list in your first pull request.

**Happy coding**!
