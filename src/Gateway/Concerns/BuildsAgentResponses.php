<?php

namespace AiBatch\Gateway\Concerns;

use Illuminate\Support\Collection;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Turns a single parsed step into the same AgentResponse shapes the synchronous loop produces.
 */
trait BuildsAgentResponses
{
    protected function toAgentResponse(StepResponse $step, string $invocationId): AgentResponse
    {
        $steps = new Collection([
            new Step($step->text, $step->toolCalls, [], $step->finishReason, $step->usage, $step->meta),
        ]);

        $messages = new Collection([
            new AssistantMessage($step->text, collect($step->toolCalls), $step->providerContentBlocks, $step->meta->provider),
        ]);

        $response = $step->structured !== null
            ? new StructuredAgentResponse($invocationId, $step->structured, $step->text, $step->usage, $step->meta)
            : new AgentResponse($invocationId, $step->text, $step->usage, $step->meta);

        $response
            ->withMessages($messages)
            ->withToolCallsAndResults(collect($step->toolCalls), new Collection)
            ->withSteps($steps)
            ->withRawResponse($step->raw);

        return $response;
    }
}
