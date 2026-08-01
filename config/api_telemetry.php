<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | When disabled, the middleware records nothing and every scheduled command
    | exits immediately without touching Redis or the database.
    |
    */

    'enabled' => env('API_TELEMETRY_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Redis buffer
    |--------------------------------------------------------------------------
    |
    | Requests are appended to a per-minute Redis list and flushed to SQL by the
    | "api-telemetry:flush" command. The TTL is a safety net: it must comfortably
    | exceed the interval at which the flush command runs.
    |
    */

    'redis' => [
        'connection' => env('API_TELEMETRY_REDIS_CONNECTION', 'default'),
        'key_prefix' => env('API_TELEMETRY_REDIS_KEY_PREFIX', 'api_telemetry:'),
        'ttl_seconds' => (int) env('API_TELEMETRY_REDIS_TTL_SECONDS', 7200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    |
    | Set "connection" to null to use the application's default connection, or
    | name a dedicated connection to keep telemetry off your primary database.
    |
    | "user_id_type" controls the column type used for the authenticated user
    | reference. Use "string" for applications with UUID/ULID primary keys.
    | Changing it after the migrations have run requires a manual migration.
    |
    */

    'database' => [
        'connection' => env('API_TELEMETRY_DB_CONNECTION'),

        'tables' => [
            'request_logs' => env('API_TELEMETRY_TABLE_REQUEST_LOGS', 'api_request_logs'),
            'usage_stats' => env('API_TELEMETRY_TABLE_USAGE_STATS', 'api_usage_stats'),
        ],

        'user_id_type' => env('API_TELEMETRY_USER_ID_TYPE', 'integer'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Recording rules
    |--------------------------------------------------------------------------
    |
    | "except" holds request paths that are never recorded. Entries are matched
    | against the request path without the leading slash and support the "*"
    | wildcard, exactly like Laravel's own middleware exclusion lists.
    |
    | "sampling_rate" is a float between 0.0 (record nothing) and 1.0 (record
    | everything). Aggregated counts are not scaled up to compensate, so keep it
    | at 1.0 unless you are deliberately trading accuracy for volume.
    |
    */

    'except' => [
        'up',
        'health',
    ],

    'sampling_rate' => (float) env('API_TELEMETRY_SAMPLING_RATE', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Privacy
    |--------------------------------------------------------------------------
    |
    | IP addresses are never stored in clear text. When "hash_ips" is enabled the
    | address is stored as a salted SHA-256 digest; when disabled no address is
    | recorded at all. Leave "ip_hash_salt" null to derive the salt from the
    | application key — note that rotating APP_KEY then invalidates the ability
    | to correlate old and new hashes.
    |
    */

    'privacy' => [
        'hash_ips' => env('API_TELEMETRY_HASH_IPS', true),
        'ip_hash_salt' => env('API_TELEMETRY_IP_HASH_SALT'),
        'record_user_agent' => env('API_TELEMETRY_RECORD_USER_AGENT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Correlation id
    |--------------------------------------------------------------------------
    |
    | Request headers inspected, in order, to resolve a correlation id. The first
    | non-empty value wins. Values are trimmed and truncated to 64 characters.
    |
    */

    'request_id_headers' => [
        'X-Request-Id',
        'X-Correlation-Id',
    ],

    /*
    |--------------------------------------------------------------------------
    | Batch sizes and retention
    |--------------------------------------------------------------------------
    */

    'flush_batch_size' => (int) env('API_TELEMETRY_FLUSH_BATCH_SIZE', 1000),

    'consolidation_chunk_size' => (int) env('API_TELEMETRY_CONSOLIDATION_CHUNK_SIZE', 2000),

    'retention_days' => (int) env('API_TELEMETRY_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Middleware auto-registration
    |--------------------------------------------------------------------------
    |
    | When enabled, the package service provider appends the LogApiRequest
    | middleware to the configured middleware group automatically. Registration
    | is skipped silently if the group does not exist, so an application without
    | API routes is unaffected. Disable this to register the middleware yourself.
    |
    */

    'auto_register_middleware' => env('API_TELEMETRY_AUTO_MIDDLEWARE', true),

    'middleware_group' => env('API_TELEMETRY_MIDDLEWARE_GROUP', 'api'),

    /*
    |--------------------------------------------------------------------------
    | Scheduling
    |--------------------------------------------------------------------------
    |
    | When enabled, the package registers the flush/consolidate/prune commands
    | on the application's scheduler. Disable this to schedule them yourself.
    | All times are interpreted in the scheduler's timezone.
    |
    */

    'schedule' => [
        'enabled' => env('API_TELEMETRY_SCHEDULE_ENABLED', true),
        'flush_minutes' => (int) env('API_TELEMETRY_SCHEDULE_FLUSH_MINUTES', 5),
        'daily_at' => env('API_TELEMETRY_SCHEDULE_DAILY_AT', '02:00'),
        'monthly_at' => env('API_TELEMETRY_SCHEDULE_MONTHLY_AT', '03:00'),
        'prune_at' => env('API_TELEMETRY_SCHEDULE_PRUNE_AT', '03:10'),
    ],

];
