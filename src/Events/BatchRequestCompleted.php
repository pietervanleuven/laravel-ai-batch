<?php

namespace AiBatch\Events;

use AiBatch\BatchHandle;
use Laravel\Ai\Responses\AgentResponse;

/**
 * Fired once per successful request as batch results are consumed. Carries the same
 * invocation id shape as PromptingAgent / AgentPrompted so per-request billing listeners keep working.
 */
class BatchRequestCompleted
{
    public function __construct(
        public string $invocationId,
        public string $customId,
        public BatchHandle $batch,
        public AgentResponse $response,
    ) {}
}
