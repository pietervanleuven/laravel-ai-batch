<?php

namespace Tests;

use AiBatch\AiBatchServiceProvider;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            AiServiceProvider::class,
            AiBatchServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('ai.default', 'openai');
        $app['config']->set('ai.providers.openai', ['driver' => 'openai', 'key' => 'test-key']);
        $app['config']->set('ai.providers.anthropic', ['driver' => 'anthropic', 'key' => 'test-key']);
        $app['config']->set('ai.providers.openrouter', ['driver' => 'openrouter', 'key' => 'test-key']);
    }
}
