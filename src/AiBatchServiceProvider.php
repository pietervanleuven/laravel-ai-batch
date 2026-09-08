<?php

namespace AiBatch;

use AiBatch\Contracts\BatchStore;
use AiBatch\Requests\Resolver;
use AiBatch\Storage\ArrayBatchStore;
use AiBatch\Storage\DatabaseBatchStore;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\ServiceProvider;

class AiBatchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-batch.php', 'ai-batch');

        $this->app->singleton(BatchStore::class, function (Container $app): BatchStore {
            $store = $app['config']->get('ai-batch.store', 'database');

            return match ($store) {
                'database', null => new DatabaseBatchStore(
                    $app->make(ConnectionResolverInterface::class),
                    $app['config']->get('ai-batch.database.connection'),
                    $app['config']->get('ai-batch.database.table', 'ai_batch_requests'),
                ),
                'array' => new ArrayBatchStore,
                default => $app->make($store),
            };
        });

        $this->app->singleton(BatchManager::class, fn (Container $app): BatchManager => new BatchManager(
            $app, $app->make(Dispatcher::class), $app->make(BatchStore::class),
        ));

        $this->app->singleton(Resolver::class, fn (Container $app): Resolver => new Resolver(
            $app->make(BatchManager::class),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/ai-batch.php' => config_path('ai-batch.php'),
            ], 'ai-batch-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'ai-batch-migrations');
        }
    }
}
