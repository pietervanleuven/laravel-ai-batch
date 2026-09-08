<?php

namespace Tests\Fixtures\Agents;

use AiBatch\Resolvable;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Promptable;

#[MaxTokens(512), Temperature(0.2)]
class AssistantAgent implements Agent, HasMiddleware
{
    use Promptable, Resolvable;

    /** @var array<int, mixed> */
    protected array $middleware = [];

    public function instructions(): string
    {
        return 'You are a helpful assistant that responds concisely.';
    }

    /** @return array<int, mixed> */
    public function middleware(): array
    {
        return $this->middleware;
    }

    /** @param  array<int, mixed>  $middleware */
    public function withMiddleware(array $middleware): self
    {
        $this->middleware = $middleware;

        return $this;
    }
}
