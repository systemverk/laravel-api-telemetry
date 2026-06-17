# Laravel API Telemetry

Lightweight API observability for Laravel applications.

The package captures API requests, buffers them in Redis, stores raw entries in SQL, and builds
daily and monthly usage stats.

## Quick Start (Packagist)

### 1. Install

```bash
composer require systemverk/laravel-api-telemetry
```

### 2. Run migrations

```bash
php artisan migrate
```

This creates two tables:

- `api_request_logs` (raw request telemetry)
- `api_usage_stats` (daily and monthly aggregates)

### 3. Ensure Redis is configured

The package writes request events to Redis before flushing to SQL.

- Redis connection config must exist under `database.redis`
- Default connection used by the package: `default`
- Override with env: `API_REQUEST_LOGGING_REDIS_CONNECTION`

### 4. Run Laravel scheduler

Auto-scheduled commands are registered by default, but they only run if your scheduler runs.

Production cron example:

```cron
* * * * * php /path/to/app/artisan schedule:run >> /dev/null 2>&1
```

Development alternative:

```bash
php artisan schedule:work
```

### 5. Verify telemetry is flowing

```bash
php artisan api-logs:flush
php artisan api-logs:consolidate-daily --date=2026-06-16
php artisan schedule:list
```

## What Runs Automatically

When installed with default config:

1. Middleware `LogApiRequest` is appended to the `api` middleware group.
2. Scheduler entries are registered:

| Command | Frequency | Purpose |
|---|---|---|
| `api-logs:flush --max-minutes=5` | every minute | Redis buffer to `api_request_logs` |
| `api-logs:consolidate-daily` | daily at 02:00 | Raw logs to daily stats |
| `api-logs:consolidate-monthly` | monthly on day 1 at 03:00 | Daily stats to monthly stats |
| `api-logs:prune` | daily at 03:10 | Delete old raw logs |

## Configuration

Defaults are usable as-is. Publish config if you want to override values:

```bash
php artisan vendor:publish --tag=api-telemetry-config
```

Then configure through `config/api_request_logging.php` or env:

| Key | Env | Default | Description |
|---|---|---|---|
| `enabled` | `API_REQUEST_LOGGING_ENABLED` | `true` | Master on/off switch |
| `redis_connection` | `API_REQUEST_LOGGING_REDIS_CONNECTION` | `default` | Redis connection name |
| `redis_key_prefix` | `API_REQUEST_LOGGING_REDIS_KEY_PREFIX` | `api_request_logs:` | Redis key prefix |
| `redis_ttl_seconds` | `API_REQUEST_LOGGING_REDIS_TTL_SECONDS` | `7200` | Buffer key TTL |
| `flush_batch_size` | `API_REQUEST_LOGGING_FLUSH_BATCH_SIZE` | `1000` | SQL insert batch size |
| `retention_days` | `API_REQUEST_LOGGING_RETENTION_DAYS` | `90` | Raw-log retention period |
| `consolidation_chunk_size` | `API_REQUEST_LOGGING_CONSOLIDATION_CHUNK_SIZE` | `2000` | Read chunk size for daily rollup |
| `auto_register_middleware` | `API_REQUEST_LOGGING_AUTO_MIDDLEWARE` | `true` | Auto-append middleware to `api` group |
| `schedule.enabled` | `API_REQUEST_LOGGING_SCHEDULE_ENABLED` | `true` | Auto-register scheduled commands |
| `schedule.flush_minutes` | `API_REQUEST_LOGGING_SCHEDULE_FLUSH_MINUTES` | `5` | `--max-minutes` used by flush |
| `schedule.daily_at` | `API_REQUEST_LOGGING_SCHEDULE_DAILY_AT` | `02:00` | Daily consolidation time |
| `schedule.monthly_at` | `API_REQUEST_LOGGING_SCHEDULE_MONTHLY_AT` | `03:00` | Monthly consolidation time |
| `schedule.prune_at` | `API_REQUEST_LOGGING_SCHEDULE_PRUNE_AT` | `03:10` | Prune command time |

## Manual Wiring (Optional)

If you want full control, set:

- `auto_register_middleware=false`
- `schedule.enabled=false`

Then register middleware and schedule manually:

```php
// bootstrap/app.php
use Systemverk\LaravelApiTelemetry\Http\Middleware\LogApiRequest;

->withMiddleware(function (Middleware $middleware) {
        $middleware->api(append: [LogApiRequest::class]);
})

// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('api-logs:flush --max-minutes=5')->everyMinute();
Schedule::command('api-logs:consolidate-daily')->dailyAt('02:00');
Schedule::command('api-logs:consolidate-monthly')->monthlyOn(1, '03:00');
Schedule::command('api-logs:prune')->dailyAt('03:10');
```

## Data Model

### `api_request_logs` (raw)

One row per API request, including:

- `requested_at` (UTC)
- `method`, `path`, `route_name`
- `status_code`, `duration_ms`
- `user_id` (nullable)
- `ip_hash` (salted SHA-256)
- `user_agent` (max 512 chars)
- `request_id` (UUID when present and valid)

### `api_usage_stats` (aggregated)

One row per `(period_type, period_start, actor_key)` where:

- `period_type` is `day` or `month`
- `actor_key` is `guest` or `user:{id}`

Consolidation is idempotent via upsert, so reruns do not double-count.

Manual backfill examples:

```bash
php artisan api-logs:consolidate-daily --date=2026-06-16
php artisan api-logs:consolidate-monthly --month=2026-06
```

Invalid `--date` or `--month` values return a clear error message and non-zero exit code.

## Privacy

- IP addresses are never stored in clear text (only salted SHA-256 hash)
- Request body is not stored
- Headers are not stored except request id (`X-Request-Id` / `X-Correlation-Id`)

## Testing

```bash
composer install
composer test
composer analyse
```

## Contributing

Contribution and release process documentation is available in `CONTRIBUTING.md`.

## License

MIT
