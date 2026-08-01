# Laravel API Telemetry

[![CI](https://github.com/systemverk/laravel-api-telemetry/actions/workflows/ci.yml/badge.svg)](https://github.com/systemverk/laravel-api-telemetry/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/systemverk/laravel-api-telemetry.svg)](https://packagist.org/packages/systemverk/laravel-api-telemetry)
[![License](https://img.shields.io/packagist/l/systemverk/laravel-api-telemetry.svg)](LICENSE)

Lightweight API observability for Laravel.

Every API request is buffered in Redis after the response has been sent, flushed
to SQL in batches by a scheduled command, and rolled up into daily and monthly
per-actor usage statistics.

## Requirements

| | Supported |
|---|---|
| PHP | 8.2, 8.3, 8.4 |
| Laravel | 11.x, 12.x |
| Redis client | `ext-redis` (recommended) or `predis/predis` |
| Database | MySQL, MariaDB, PostgreSQL, SQLite, SQL Server |

## Quick Start

### 1. Install

```bash
composer require systemverk/laravel-api-telemetry
```

### 2. Run migrations

```bash
php artisan migrate
```

The package ships its migrations and loads them automatically — there is nothing
to publish. Two tables are created:

- `api_request_logs` — raw request telemetry
- `api_usage_stats` — daily and monthly aggregates

### 3. Make sure Redis is configured

Requests are appended to a Redis list before being flushed to SQL.

- A connection must exist under `database.redis` (or `database.redis.clusters`)
- The package uses the `default` connection unless told otherwise
- Override with `API_TELEMETRY_REDIS_CONNECTION`

If the configured connection does not exist, the middleware records nothing
rather than throwing — telemetry never takes an application down.

### 4. Run the scheduler

The commands are registered on the scheduler automatically, but they only run if
your scheduler runs.

```cron
* * * * * php /path/to/app/artisan schedule:run >> /dev/null 2>&1
```

During development:

```bash
php artisan schedule:work
```

### 5. Verify telemetry is flowing

```bash
php artisan api-telemetry:flush
php artisan api-telemetry:consolidate-daily --date=2026-06-16
php artisan schedule:list
```

## What Runs Automatically

With the default configuration:

1. `LogApiRequest` is appended to the `api` middleware group. If that group does
   not exist, registration is skipped silently.
2. Four scheduled commands are registered, each `withoutOverlapping()`:

| Command | Frequency | Purpose |
|---|---|---|
| `api-telemetry:flush --max-minutes=5` | every minute | Redis buffer → `api_request_logs` |
| `api-telemetry:consolidate-daily` | daily at 02:00 | Raw logs → daily stats |
| `api-telemetry:consolidate-monthly` | monthly on day 1 at 03:00 | Daily stats → monthly stats |
| `api-telemetry:prune` | daily at 03:10 | Delete raw logs past retention |

## How It Works

```
request ──► LogApiRequest::handle()      records a start timestamp only
        ──► ...application...
        ──► response sent to the client
        ──► LogApiRequest::terminate()   RPUSH onto api_telemetry:requests:<YYYYMMDDHHMM>
                                          │
   every minute: api-telemetry:flush ─────┘
        RENAMENX the minute list to a private processing key, insert in batches,
        then delete. The key is tracked in a Redis set until the write is
        confirmed, so a crashed or failed flush is retried on the next run
        instead of silently losing entries.
```

Buffering happens in `terminate()`, so the two Redis round trips are off the
critical path of the response.

## Configuration

Defaults are usable as-is. Publish the config only if you need to change them:

```bash
php artisan vendor:publish --tag=api-telemetry-config
```

| Key | Env | Default | Description |
|---|---|---|---|
| `enabled` | `API_TELEMETRY_ENABLED` | `true` | Master on/off switch |
| `redis.connection` | `API_TELEMETRY_REDIS_CONNECTION` | `default` | Redis connection name |
| `redis.key_prefix` | `API_TELEMETRY_REDIS_KEY_PREFIX` | `api_telemetry:` | Redis key prefix |
| `redis.ttl_seconds` | `API_TELEMETRY_REDIS_TTL_SECONDS` | `7200` | Buffer key TTL (min 60) |
| `database.connection` | `API_TELEMETRY_DB_CONNECTION` | `null` | Dedicated connection, or the app default |
| `database.tables.request_logs` | `API_TELEMETRY_TABLE_REQUEST_LOGS` | `api_request_logs` | Raw log table name |
| `database.tables.usage_stats` | `API_TELEMETRY_TABLE_USAGE_STATS` | `api_usage_stats` | Aggregate table name |
| `database.user_id_type` | `API_TELEMETRY_USER_ID_TYPE` | `integer` | Set to `string` for UUID/ULID user keys |
| `except` | — | `['up', 'health']` | Paths never recorded (supports `*`) |
| `sampling_rate` | `API_TELEMETRY_SAMPLING_RATE` | `1.0` | Fraction of requests recorded |
| `privacy.hash_ips` | `API_TELEMETRY_HASH_IPS` | `true` | Store a salted hash, or nothing at all |
| `privacy.ip_hash_salt` | `API_TELEMETRY_IP_HASH_SALT` | `null` | Defaults to `app.key` |
| `privacy.record_user_agent` | `API_TELEMETRY_RECORD_USER_AGENT` | `true` | Store the user agent string |
| `request_id_headers` | — | `X-Request-Id`, `X-Correlation-Id` | Correlation-id headers, in priority order |
| `flush_batch_size` | `API_TELEMETRY_FLUSH_BATCH_SIZE` | `1000` | Rows per insert statement |
| `consolidation_chunk_size` | `API_TELEMETRY_CONSOLIDATION_CHUNK_SIZE` | `2000` | Read chunk size during rollup |
| `retention_days` | `API_TELEMETRY_RETENTION_DAYS` | `90` | Raw-log retention period |
| `auto_register_middleware` | `API_TELEMETRY_AUTO_MIDDLEWARE` | `true` | Auto-append the middleware |
| `middleware_group` | `API_TELEMETRY_MIDDLEWARE_GROUP` | `api` | Group to append the middleware to |
| `schedule.enabled` | `API_TELEMETRY_SCHEDULE_ENABLED` | `true` | Auto-register scheduled commands |
| `schedule.flush_minutes` | `API_TELEMETRY_SCHEDULE_FLUSH_MINUTES` | `5` | `--max-minutes` used by flush |
| `schedule.daily_at` | `API_TELEMETRY_SCHEDULE_DAILY_AT` | `02:00` | Daily consolidation time |
| `schedule.monthly_at` | `API_TELEMETRY_SCHEDULE_MONTHLY_AT` | `03:00` | Monthly consolidation time |
| `schedule.prune_at` | `API_TELEMETRY_SCHEDULE_PRUNE_AT` | `03:10` | Prune time |

### Excluding noisy endpoints

```php
'except' => [
    'up',
    'health',
    'webhooks/*',
],
```

### Sampling high-volume traffic

```php
'sampling_rate' => 0.1, // record roughly one request in ten
```

Aggregated counts are **not** scaled back up, so a sampled deployment reports
sampled numbers. Keep the rate at `1.0` if the counts must be exact.

### UUID or ULID user keys

Set `database.user_id_type` to `string` **before** running the migrations. With
the default `integer` setting, non-numeric identifiers are recorded as `null`
rather than being silently coerced to `0`.

## Manual Wiring

For full control, disable both switches:

```dotenv
API_TELEMETRY_AUTO_MIDDLEWARE=false
API_TELEMETRY_SCHEDULE_ENABLED=false
```

Then register everything yourself:

```php
// bootstrap/app.php
use Systemverk\LaravelApiTelemetry\Http\Middleware\LogApiRequest;

->withMiddleware(function (Middleware $middleware) {
    $middleware->api(append: [LogApiRequest::class]);
})

// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('api-telemetry:flush --max-minutes=5')->everyMinute()->withoutOverlapping();
Schedule::command('api-telemetry:consolidate-daily')->dailyAt('02:00');
Schedule::command('api-telemetry:consolidate-monthly')->monthlyOn(1, '03:00');
Schedule::command('api-telemetry:prune')->dailyAt('03:10');
```

## Data Model

All timestamps are stored in **UTC**, independent of `app.timezone`.

### `api_request_logs` (raw)

One row per recorded request:

| Column | Notes |
|---|---|
| `requested_at` | UTC |
| `method`, `path`, `route_name` | `path` truncated to 1024 chars |
| `status_code`, `duration_ms` | |
| `user_id` | Nullable; integer or string per config |
| `ip_hash` | Salted SHA-256, or null |
| `user_agent` | Truncated to 512 chars, or null |
| `request_id` | First matching correlation header, 64 chars |

### `api_usage_stats` (aggregated)

One row per `(period_type, period_start, actor_key)`:

- `period_type` is `day` or `month`
- `actor_key` is `guest` or `user:{id}`
- `total_requests` plus a counter per status class: `responses_1xx` through
  `responses_5xx`

Consolidation is idempotent — reruns upsert rather than accumulate, so a backfill
can be re-run safely:

```bash
php artisan api-telemetry:consolidate-daily --date=2026-06-16
php artisan api-telemetry:consolidate-monthly --month=2026-06
```

Invalid `--date` or `--month` values produce a clear error and exit code 1.

## Querying the Data

Both models are plain Eloquent models and are part of the public API.

```php
use Systemverk\LaravelApiTelemetry\Models\ApiRequestLog;
use Systemverk\LaravelApiTelemetry\Models\ApiUsageStat;

// Slowest endpoints in the last 24 hours
ApiRequestLog::query()
    ->where('requested_at', '>=', now()->utc()->subDay())
    ->selectRaw('path, count(*) as hits, avg(duration_ms) as avg_ms')
    ->groupBy('path')
    ->orderByDesc('avg_ms')
    ->limit(10)
    ->get();

// Server errors today
ApiRequestLog::query()->statusClass(5)->whereDate('requested_at', today())->count();

// Monthly usage for one customer
ApiUsageStat::query()->monthly()->where('actor_key', 'user:42')->get();
```

## Privacy

- IP addresses are never stored in clear text — only a salted SHA-256 digest, or
  nothing when `privacy.hash_ips` is disabled
- Request and response bodies are never recorded
- No headers are recorded except the configured correlation id
- Request **paths are stored verbatim**. If your API puts secrets in the URL
  path, exclude those routes via `except`

Rotating `APP_KEY` changes the default IP hash salt, so hashes recorded before
and after a rotation will not correlate. Set an explicit
`API_TELEMETRY_IP_HASH_SALT` if that matters to you.

## Testing

```bash
composer install
composer test
composer analyse
```

The suite runs against SQLite in memory with an in-memory Redis double, so no
services are required.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security issues: [SECURITY.md](SECURITY.md).

## License

MIT — see [LICENSE](LICENSE).
