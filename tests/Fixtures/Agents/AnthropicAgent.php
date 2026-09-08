<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

#[Provider(Lab::Anthropic)]
class AnthropicAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Summarise the given text in one sentence.';
    }
}
