<?php

namespace Tests\Fixtures\Agents;

use AiBatch\Resolvable;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;

class ConversationalAgent implements Agent, Conversational
{
    use Promptable, Resolvable;

    /** @param  array<int, Message>  $messages */
    public function __construct(protected array $messages = []) {}

    public function instructions(): string
    {
        return 'You are an assistant that remembers the conversation so far.';
    }

    public function messages(): array
    {
        return $this->messages;
    }
}
