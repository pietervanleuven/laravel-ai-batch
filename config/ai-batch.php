<?php

use AiBatch\Gateway\Anthropic\AnthropicBatchGateway;
use AiBatch\Gateway\OpenAi\OpenAiBatchGateway;
use AiBatch\Gateway\OpenRouter\OpenRouterBatchGateway;

return [

    /*
    |--------------------------------------------------------------------------
    | Batch Gateways
    |--------------------------------------------------------------------------
    |
    | Maps a laravel/ai provider *driver* to the gateway that implements its
    | vendor batch API. Providers whose driver is not listed here cannot be
    | batched and will throw UnsupportedBatchProviderException on submit.
    |
    */

    'gateways' => [
        'openai' => OpenAiBatchGateway::class,
        'anthropic' => AnthropicBatchGateway::class,
        'openrouter' => OpenRouterBatchGateway::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Batch Store
    |--------------------------------------------------------------------------
    |
    | Where the per-request context of a submitted batch (invocation id,
    | structured output flag, agent) is kept so results fetched in another
    | process, such as the PollBatch job, keep their identity. "database"
    | needs the package migration; "array" lives for the process only.
    |
    */

    'store' => env('AI_BATCH_STORE', 'database'),

    'database' => [
        'connection' => env('AI_BATCH_DB_CONNECTION'),
        'table' => 'ai_batch_requests',
    ],

    /*
    |--------------------------------------------------------------------------
    | Polling
    |--------------------------------------------------------------------------
    |
    | The PollBatch job releases itself back onto the queue until the batch
    | is terminal, waiting "poll_interval" seconds before the first check
    | and doubling each time up to "poll_max_interval". It gives up after
    | "poll_timeout_hours".
    |
    */

    'poll_interval' => (int) env('AI_BATCH_POLL_INTERVAL', 60),
    'poll_max_interval' => (int) env('AI_BATCH_POLL_MAX_INTERVAL', 900),
    'poll_timeout_hours' => (int) env('AI_BATCH_POLL_TIMEOUT_HOURS', 48),

];
