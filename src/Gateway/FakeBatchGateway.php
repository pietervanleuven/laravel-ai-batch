<?php

namespace AiBatch\Gateway;

use AiBatch\BatchHandle;
use AiBatch\BatchRequestCounts;
use AiBatch\BatchRequestFailed;
use AiBatch\BatchStatus;
use AiBatch\Contracts\BatchGateway;
use AiBatch\Gateway\Concerns\BuildsAgentResponses;
use AiBatch\Gateway\Concerns\ResolvesRequestContext;
use AiBatch\Requests\RequestContext;
use AiBatch\Requests\ResolvedRequest;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;

use function Laravel\Ai\generate_fake_data_for_json_schema_type;

/**
 * In-memory batch gateway for tests. Batches complete immediately.
 *
 * Results come from, in order: a response configured on Batch::fake() for the custom id (or next in sequence),
 * the agent's own Agent::fake() responses, or a generated placeholder.
 */
class FakeBatchGateway implements BatchGateway
{
    use BuildsAgentResponses, ResolvesRequestContext;

    /**
     * @var array<int, array{id: string, provider: TextProvider, requests: array<string, ResolvedRequest>, options: array<string, mixed>}>
     */
    protected array $submissions = [];

    /**
     * @var array<string, BatchHandle>
     */
    protected array $handles = [];

    /**
     * @var array<string, BatchStatus>
     */
    protected array $statuses = [];

    protected int $sequence = 0;

    /**
     * @param  Closure|array<string|int, mixed>  $responses
     */
    public function __construct(protected Closure|array $responses = []) {}

    public function textEndpoint(): string
    {
        return '/fake';
    }

    public function resolveTextRequest(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
    ): array {
        $user = collect($messages)->last(fn ($message): bool => $message instanceof UserMessage);

        return [
            'model' => $model,
            'instructions' => $instructions,
            'prompt' => $user instanceof UserMessage ? $user->content : '',
            'messages' => count($messages),
            'tools' => count($tools),
            'schema' => $schema,
        ];
    }

    public function submitBatch(TextProvider $provider, array $requests, array $options = []): BatchHandle
    {
        $id = 'fake-batch-'.(count($this->submissions) + 1);

        $this->submissions[] = compact('id', 'provider', 'requests', 'options');
        $this->statuses[$id] = BatchStatus::Completed;

        $this->handles[$id] = $this->handle($id, $provider, count($requests));

        // Hand out a copy, as a real gateway would, so nothing here keeps the caller's handle alive.
        return clone $this->handles[$id];
    }

    public function retrieveBatch(TextProvider $provider, string $id): BatchHandle
    {
        return isset($this->handles[$id]) ? clone $this->handles[$id] : $this->handle($id, $provider, 0);
    }

    public function cancelBatch(TextProvider $provider, string $id): BatchHandle
    {
        $this->statuses[$id] = BatchStatus::Cancelled;

        return clone ($this->handles[$id] = $this->handle($id, $provider, $this->handles[$id]->counts->total ?? 0));
    }

    public function batchResults(TextProvider $provider, BatchHandle $batch, array $contexts = [], ?bool $structured = null): iterable
    {
        $submission = collect($this->submissions)->firstWhere('id', $batch->id);

        foreach ($submission['requests'] ?? [] as $customId => $request) {
            yield (string) $customId => $this->resultFor((string) $customId, $request, $provider, $contexts);
        }
    }

    /**
     * Mark a batch as still running (or any other status) so polling can be exercised.
     */
    public function setStatus(string $id, BatchStatus $status): static
    {
        $this->statuses[$id] = $status;

        if (isset($this->handles[$id])) {
            $this->handles[$id] = $this->handle($id, $this->handles[$id]->provider, $this->handles[$id]->counts->total);
        }

        return $this;
    }

    /**
     * @return Collection<int, array{id: string, provider: TextProvider, requests: array<string, ResolvedRequest>, options: array<string, mixed>}>
     */
    public function submissions(): Collection
    {
        return new Collection($this->submissions);
    }

    protected function handle(string $id, TextProvider $provider, int $total): BatchHandle
    {
        $status = $this->statuses[$id] ?? BatchStatus::Completed;

        return new BatchHandle(
            id: $id,
            status: $status,
            provider: $provider,
            counts: new BatchRequestCounts(
                total: $total,
                completed: $status === BatchStatus::Completed ? $total : 0,
                processing: $status->isTerminal() ? 0 : $total,
            ),
            createdAt: CarbonImmutable::now(),
            expiresAt: CarbonImmutable::now()->addDay(),
            endedAt: $status->isTerminal() ? CarbonImmutable::now() : null,
            raw: ['id' => $id, 'fake' => true],
            resultsAvailable: $status->isTerminal() && $status !== BatchStatus::Failed,
        );
    }

    /**
     * @param  array<string, RequestContext>  $contexts
     */
    protected function resultFor(string $customId, ResolvedRequest $request, TextProvider $provider, array $contexts): AgentResponse|BatchRequestFailed
    {
        $configured = $this->configuredResponse($customId, $request);

        if ($configured instanceof BatchRequestFailed) {
            return $configured;
        }

        $step = $configured === null
            ? $this->stepFromAgentFake($request, $provider)
            : $this->toStep($configured, $request, $provider);

        return $this->toAgentResponse($step, $this->invocationIdFor($customId, $contexts));
    }

    protected function configuredResponse(string $customId, ResolvedRequest $request): mixed
    {
        if ($this->responses instanceof Closure) {
            return ($this->responses)($customId, $request);
        }

        // A list is a sequence consumed in submission order; a map is keyed by custom id. Checked in this
        // order because PHP coerces numeric-string keys like "1" to int, which would otherwise make a
        // custom id "1" hit index 1 of a sequence.
        if (array_is_list($this->responses)) {
            return $this->responses[$this->sequence++] ?? null;
        }

        return $this->responses[$customId] ?? null;
    }

    /**
     * Fall back to Agent::fake() responses, or a generated placeholder.
     */
    protected function stepFromAgentFake(ResolvedRequest $request, TextProvider $provider): StepResponse
    {
        $agent = $request->prompt->agent;

        if (Ai::hasFakeGatewayFor($agent)) {
            return Ai::fakeGatewayFor($agent)->generateTextStep(
                $provider,
                $request->model,
                null,
                [new UserMessage($request->prompt->prompt, $request->prompt->attachments->all())],
                [],
                $request->schema,
                null,
                null,
                new StepContext(0, true),
            );
        }

        $placeholder = $request->isStructured()
            ? generate_fake_data_for_json_schema_type(new ObjectType($request->schema))
            : 'Fake batch response for prompt: '.Str::words($request->prompt->prompt, 10);

        return $this->toStep($placeholder, $request, $provider);
    }

    protected function toStep(mixed $response, ResolvedRequest $request, TextProvider $provider): StepResponse
    {
        $meta = new Meta($provider->name(), $request->model);

        return match (true) {
            $response instanceof Closure => $this->toStep($response($request->prompt->prompt, $request), $request, $provider),
            $response instanceof StepResponse => $response,
            $response instanceof StructuredTextResponse => new StepResponse($response->text, [], FinishReason::Stop, $response->usage, $response->meta, $response->structured),
            $response instanceof TextResponse => new StepResponse($response->text, [], FinishReason::Stop, $response->usage, $response->meta),
            is_array($response) => new StepResponse(json_encode($response) ?: '', [], FinishReason::Stop, new Usage, $meta, $response),
            default => new StepResponse((string) $response, [], FinishReason::Stop, new Usage, $meta),
        };
    }
}
