<?php

namespace AiBatch;

use AiBatch\Exceptions\BatchException;
use AiBatch\Requests\ResolvedRequest;
use AiBatch\Requests\Resolver;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Enums\Lab;

class PendingBatch
{
    /**
     * @var array<string, ResolvedRequest>
     */
    protected array $requests = [];

    /**
     * @param  array<string, ResolvedRequest>  $requests
     */
    public function __construct(protected BatchManager $manager, array $requests = [])
    {
        foreach ($requests as $customId => $request) {
            $this->add((string) $customId, $request);
        }
    }

    /**
     * Add a request to the batch.
     *
     * Pass a ResolvedRequest, or an agent plus a prompt to resolve it here.
     *
     * @param  array<int, mixed>  $attachments
     * @param  Lab|array<int|string, Lab|string|null>|string|null  $provider
     */
    public function add(
        string $customId,
        ResolvedRequest|Agent $request,
        ?string $prompt = null,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): static {
        if ($request instanceof Agent) {
            if ($prompt === null) {
                throw new BatchException('A prompt is required when adding an agent to a batch.');
            }

            $request = app(Resolver::class)->resolve($request, $prompt, $attachments, $provider, $model);
        }

        if (isset($this->requests[$customId])) {
            throw new BatchException("Duplicate custom id [{$customId}] in batch.");
        }

        $this->requests[$customId] = $request;

        return $this;
    }

    /**
     * @return array<string, ResolvedRequest>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * Submit the batch to the provider.
     *
     * @param  Lab|string|TextProvider|null  $provider  defaults to the provider the requests were resolved for
     * @param  string|null  $model  when given, every request must target this model
     * @param  array<string, mixed>  $options  provider-specific submission options (e.g. OpenAI "metadata", "completion_window")
     */
    public function submit(Lab|string|TextProvider|null $provider = null, ?string $model = null, array $options = []): BatchHandle
    {
        if ($this->requests === []) {
            throw new BatchException('Cannot submit an empty batch.');
        }

        $provider = $this->manager->provider($provider ?? reset($this->requests)->provider);

        foreach ($this->requests as $customId => $request) {
            if ($request->provider->name() !== $provider->name()) {
                throw new BatchException(sprintf(
                    'Request [%s] was resolved for provider [%s] but the batch targets [%s]. Resolve every request for the same provider.',
                    $customId, $request->provider->name(), $provider->name(),
                ));
            }

            if ($model !== null && $request->model !== $model) {
                throw new BatchException(sprintf(
                    'Request [%s] targets model [%s] but the batch requires [%s].',
                    $customId, $request->model, $model,
                ));
            }
        }

        return $this->manager->submit($provider, $this->requests, $options);
    }
}
