<?php

return [
    'enabled' => env('API_REQUEST_LOGGING_ENABLED', true),

    'redis_connection' => env('API_REQUEST_LOGGING_REDIS_CONNECTION', 'default'),

    'redis_key_prefix' => env('API_REQUEST_LOGGING_REDIS_KEY_PREFIX', 'api_request_logs:'),

    'redis_ttl_seconds' => (int) env('API_REQUEST_LOGGING_REDIS_TTL_SECONDS', 7200),

    'flush_batch_size' => (int) env('API_REQUEST_LOGGING_FLUSH_BATCH_SIZE', 1000),

    'retention_days' => (int) env('API_REQUEST_LOGGING_RETENTION_DAYS', 90),

    'consolidation_chunk_size' => (int) env('API_REQUEST_LOGGING_CONSOLIDATION_CHUNK_SIZE', 2000),

    /*
    |--------------------------------------------------------------------------
    | Middleware auto-registration
    |--------------------------------------------------------------------------
    |
    | When enabled, the package service provider appends the LogApiRequest
    | middleware to the application's "api" middleware group automatically.
    | Disable this if you prefer to register the middleware manually.
    |
    */
    'auto_register_middleware' => env('API_REQUEST_LOGGING_AUTO_MIDDLEWARE', true),

    /*
    |--------------------------------------------------------------------------
    | Scheduling
    |--------------------------------------------------------------------------
    |
    | When enabled, the package registers the flush/consolidate/prune commands
    | on the application's scheduler. Disable this to schedule them yourself.
    |
    */
    'schedule' => [
        'enabled' => env('API_REQUEST_LOGGING_SCHEDULE_ENABLED', true),
        'flush_minutes' => (int) env('API_REQUEST_LOGGING_SCHEDULE_FLUSH_MINUTES', 5),
        'daily_at' => env('API_REQUEST_LOGGING_SCHEDULE_DAILY_AT', '02:00'),
        'monthly_at' => env('API_REQUEST_LOGGING_SCHEDULE_MONTHLY_AT', '03:00'),
        'prune_at' => env('API_REQUEST_LOGGING_SCHEDULE_PRUNE_AT', '03:10'),
    ],
];
