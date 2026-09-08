<?php

namespace Tests\Fixtures\Agents;

use AiBatch\Resolvable;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

#[Provider(Lab::OpenRouter)]
class OpenRouterAgent implements Agent
{
    use Promptable, Resolvable;

    public function instructions(): string
    {
        return 'Summarise the given text in one sentence.';
    }
}
