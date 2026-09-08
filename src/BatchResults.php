<?php

namespace AiBatch;

use Illuminate\Support\Collection;
use Laravel\Ai\Responses\AgentResponse;

/**
 * @extends Collection<string, AgentResponse|BatchRequestFailed>
 */
class BatchResults extends Collection
{
    /**
     * Only the results that produced a response.
     *
     * @return Collection<string, AgentResponse>
     */
    public function successful(): Collection
    {
        return $this->filter(fn ($result): bool => $result instanceof AgentResponse);
    }

    /**
     * Only the results that failed.
     *
     * @return Collection<string, BatchRequestFailed>
     */
    public function failed(): Collection
    {
        return $this->filter(fn ($result): bool => $result instanceof BatchRequestFailed);
    }

    /**
     * Determine if any request in the batch failed.
     */
    public function hasFailures(): bool
    {
        return $this->contains(fn ($result): bool => $result instanceof BatchRequestFailed);
    }

    /**
     * Throw the first failure, if any.
     */
    public function throw(): static
    {
        if ($failure = $this->failed()->first()) {
            throw $failure->toException();
        }

        return $this;
    }
}
