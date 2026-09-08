<?php

namespace Tests\Fixtures\Agents;

use AiBatch\Resolvable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

#[Strict]
class StructuredAgent implements Agent, HasStructuredOutput
{
    use Promptable, Resolvable;

    public function instructions(): string
    {
        return 'Classify the sentiment of the given text.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'sentiment' => $schema->string()->required(),
        ];
    }
}
