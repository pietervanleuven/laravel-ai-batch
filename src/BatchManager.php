<?php

namespace AiBatch;

use AiBatch\Contracts\BatchGateway;
use AiBatch\Contracts\BatchStore;
use AiBatch\Contracts\ResolvesTextRequests;
use AiBatch\Events\BatchRequestCompleted;
use AiBatch\Events\BatchRequestErrored;
use AiBatch\Events\BatchSubmitted;
use AiBatch\Exceptions\UnsupportedBatchProviderException;
use AiBatch\Gateway\FakeBatchGateway;
use AiBatch\Requests\ResolvedRequest;
use Closure;
use Generator;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Responses\AgentResponse;
use PHPUnit\Framework\Assert as PHPUnit;

class BatchManager
{
    /**
     * Resolved gateway instances, keyed by driver.
     *
     * @var array<string, BatchGateway>
     */
    protected array $gateways = [];

    /**
     * Custom gateway resolvers, keyed by driver.
     *
     * @var array<string, Closure(Container): BatchGateway>
     */
    protected array $customGateways = [];

    protected ?FakeBatchGateway $fake = null;

    /**
     * The gateway used for agents faked through Agent::fake() when batches themselves are not faked.
     */
    protected ?FakeBatchGateway $agentFake = null;

    public function __construct(
        protected Container $container,
        protected Dispatcher $events,
        protected BatchStore $store,
    ) {}

    /**
     * Start building a batch from resolved requests keyed by custom id.
     *
     * @param  array<string, ResolvedRequest>  $requests
     */
    public function of(array $requests = []): PendingBatch
    {
        return new PendingBatch($this, $requests);
    }

    /**
     * Retrieve a previously submitted batch by its provider id.
     */
    public function find(string $id, Lab|string|TextProvider|null $provider = null): BatchHandle
    {
        return $this->retrieve($id, $this->provider($provider));
    }

    /**
     * Submit resolved requests as a batch.
     *
     * @param  array<string, ResolvedRequest>  $requests
     * @param  array<string, mixed>  $options
     */
    public function submit(TextProvider $provider, array $requests, array $options = []): BatchHandle
    {
        $handle = $this->gatewayFor($provider)
            ->submitBatch($provider, $requests, $options)
            ->withRequests($requests);

        $this->store->store($handle, $requests);

        $this->events->dispatch(new BatchSubmitted($handle, $requests));

        return $handle;
    }

    public function retrieve(string $id, TextProvider $provider): BatchHandle
    {
        return $this->gatewayFor($provider)->retrieveBatch($provider, $id);
    }

    public function cancel(string $id, TextProvider $provider): BatchHandle
    {
        return $this->gatewayFor($provider)->cancelBatch($provider, $id);
    }

    /**
     * Fetch and parse the results of a batch, firing per-request events as they are consumed.
     */
    public function results(BatchHandle $batch, ?bool $structured = null): BatchResults
    {
        $results = new BatchResults;

        foreach ($this->iterateResults($batch, $structured) as $customId => $result) {
            $results->put($customId, $result);
        }

        return $results;
    }

    /**
     * Stream the results of a batch one at a time, without materialising them all.
     *
     * @return Generator<string, AgentResponse|BatchRequestFailed>
     */
    public function iterateResults(BatchHandle $batch, ?bool $structured = null): Generator
    {
        $contexts = $batch->requests !== []
            ? $batch->contexts()
            : $this->store->contexts($batch->id, $batch->provider->name());

        $iterable = $this->gatewayFor($batch->provider)->batchResults(
            $batch->provider, $batch, $contexts, $structured,
        );

        foreach ($iterable as $customId => $result) {
            $request = $batch->requests[$customId] ?? null;

            if ($result instanceof AgentResponse) {
                $this->events->dispatch(new BatchRequestCompleted($result->invocationId, $customId, $batch, $result));

                if ($request instanceof ResolvedRequest) {
                    $this->events->dispatch(new AgentPrompted($result->invocationId, $request->prompt, $result));
                }
            } else {
                $this->events->dispatch(new BatchRequestErrored(
                    $request instanceof ResolvedRequest ? $request->invocationId : ($contexts[$customId]->invocationId ?? null),
                    $customId, $batch, $result,
                ));
            }

            yield $customId => $result;
        }
    }

    /**
     * Forget the stored context of a batch once the application is done with it.
     */
    public function forget(BatchHandle $batch): void
    {
        $this->store->forget($batch->id, $batch->provider->name());
    }

    /**
     * The store holding per-request context across processes.
     */
    public function store(): BatchStore
    {
        return $this->store;
    }

    /**
     * Resolve a text provider from a name, Lab case, or instance.
     */
    public function provider(Lab|string|TextProvider|null $provider = null): TextProvider
    {
        if ($provider instanceof TextProvider) {
            return $provider;
        }

        return Ai::textProvider($provider instanceof Lab ? $provider->value : $provider);
    }

    /**
     * Get the batch gateway for the given provider.
     *
     * Honours, in order: Batch::fake(), a text gateway swapped onto the provider that implements
     * BatchGateway, an Agent::fake() gateway (never hits the network), then the driver map in config.
     */
    public function gatewayFor(TextProvider $provider): BatchGateway
    {
        if ($this->fake) {
            return $this->fake;
        }

        $textGateway = method_exists($provider, 'textGateway') ? $provider->textGateway() : null;

        if ($textGateway instanceof BatchGateway) {
            return $textGateway;
        }

        if ($textGateway instanceof FakeTextGateway) {
            return $this->agentFake ??= new FakeBatchGateway;
        }

        return $this->configuredGatewayFor($provider);
    }

    /**
     * Get the request builder for the given provider, always the real one so resolve() returns the
     * body the provider would receive even while agents or batches are faked.
     */
    public function requestResolverFor(TextProvider $provider): ResolvesTextRequests
    {
        $textGateway = method_exists($provider, 'textGateway') ? $provider->textGateway() : null;

        return $textGateway instanceof BatchGateway ? $textGateway : $this->configuredGatewayFor($provider);
    }

    /**
     * Resolve the gateway registered for the provider's driver.
     */
    protected function configuredGatewayFor(TextProvider $provider): BatchGateway
    {
        $driver = $provider->driver();

        if (isset($this->gateways[$driver])) {
            return $this->gateways[$driver];
        }

        if (isset($this->customGateways[$driver])) {
            return $this->gateways[$driver] = ($this->customGateways[$driver])($this->container);
        }

        $class = config("ai-batch.gateways.{$driver}");

        if (! $class) {
            throw UnsupportedBatchProviderException::forProvider($provider);
        }

        return $this->gateways[$driver] = $this->container->make($class);
    }

    /**
     * Register a batch gateway for a driver.
     *
     * @param  Closure(Container): BatchGateway  $resolver
     */
    public function extend(string $driver, Closure $resolver): static
    {
        $this->customGateways[$driver] = $resolver;

        unset($this->gateways[$driver]);

        return $this;
    }

    /**
     * Replace every gateway with an in-memory fake.
     *
     * @param  Closure|array<string|int, mixed>  $responses  responses keyed by custom id, or a sequence
     */
    public function fake(Closure|array $responses = []): FakeBatchGateway
    {
        return $this->fake = new FakeBatchGateway($responses);
    }

    public function isFaked(): bool
    {
        return $this->fake !== null;
    }

    /**
     * Assert that a batch was submitted matching the given truth test.
     *
     * @param  Closure(array<string, ResolvedRequest>, TextProvider, array<string, mixed>): bool  $callback
     */
    public function assertSubmitted(Closure $callback): void
    {
        PHPUnit::assertTrue(
            $this->fakeOrFail()->submissions()->contains(fn (array $submission): bool => $callback(
                $submission['requests'], $submission['provider'], $submission['options'],
            )),
            'No batch matching the given truth test was submitted.',
        );
    }

    /**
     * Assert that a number of batches were submitted.
     */
    public function assertSubmittedTimes(int $times = 1): void
    {
        $count = $this->fakeOrFail()->submissions()->count();

        PHPUnit::assertSame($times, $count, "Expected {$times} batch submissions, got {$count}.");
    }

    /**
     * Assert that no batches were submitted.
     */
    public function assertNothingSubmitted(): void
    {
        PHPUnit::assertTrue($this->fakeOrFail()->submissions()->isEmpty(), 'Batches were submitted unexpectedly.');
    }

    protected function fakeOrFail(): FakeBatchGateway
    {
        return $this->fake ?? throw new \LogicException('Batches are not faked. Call Batch::fake() first.');
    }
}
