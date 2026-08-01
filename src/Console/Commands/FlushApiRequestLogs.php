<?php

namespace Systemverk\LaravelApiTelemetry\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Systemverk\LaravelApiTelemetry\Models\ApiRequestLog;
use Systemverk\LaravelApiTelemetry\Support\ApiRequestLogBuffer;
use Systemverk\LaravelApiTelemetry\Support\TelemetryConfig;

class FlushApiRequestLogs extends Command
{
    /**
     * Seconds a claimed buffer stays locked before another run may retry it.
     */
    private const CLAIM_TTL_SECONDS = 300;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api-telemetry:flush
        {--max-minutes=5 : Minutes to scan backwards from now}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Flush buffered API request logs from Redis into the database';

    private ?Connection $connection = null;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! TelemetryConfig::enabled()) {
            return self::SUCCESS;
        }

        $maxMinutes = max(1, (int) $this->option('max-minutes'));

        $recovered = $this->recoverAbandonedBuffers();
        $flushed = 0;

        foreach ($this->keysToProcess($maxMinutes) as $key) {
            $flushed += $this->claimAndDrain($key);
        }

        $total = $flushed + $recovered;

        $this->info($recovered > 0
            ? "Flushed {$total} API request logs ({$recovered} recovered from a previous run)."
            : "Flushed {$total} API request logs.");

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function keysToProcess(int $maxMinutes): array
    {
        $keys = [];
        $now = now()->utc();

        for ($i = 0; $i < $maxMinutes; $i++) {
            $keys[] = ApiRequestLogBuffer::keyForMinute($now->copy()->subMinutes($i));
        }

        return $keys;
    }

    /**
     * Atomically take ownership of a minute buffer and write it to the database.
     *
     * Requests that arrive after the rename land in a freshly created list under
     * the original key, so nothing is lost by claiming the current minute.
     */
    private function claimAndDrain(string $key): int
    {
        $processingKey = $key.':processing:'.Str::uuid();

        try {
            $redis = $this->connection();

            if ((int) $redis->llen($key) === 0) {
                return 0;
            }

            // Registered before the rename so that a crash between the two still
            // leaves a breadcrumb; recovery tolerates entries that never existed.
            $redis->sadd(ApiRequestLogBuffer::processingRegistryKey(), [$processingKey]);

            if (! $redis->renamenx($key, $processingKey)) {
                $redis->srem(ApiRequestLogBuffer::processingRegistryKey(), $processingKey);

                return 0;
            }
        } catch (\Throwable $exception) {
            $this->reportFailure('Failed to claim buffered API request logs.', $key, $exception);

            return 0;
        }

        return $this->drain($processingKey);
    }

    /**
     * Re-attempt buffers claimed by an earlier run that never confirmed a write.
     */
    private function recoverAbandonedBuffers(): int
    {
        try {
            $redis = $this->connection();
            $registryKey = ApiRequestLogBuffer::processingRegistryKey();
            $members = $redis->smembers($registryKey);
        } catch (\Throwable $exception) {
            $this->reportFailure('Failed to inspect the API telemetry processing registry.', null, $exception);

            return 0;
        }

        if (! is_array($members)) {
            return 0;
        }

        $recovered = 0;

        foreach ($members as $processingKey) {
            if (! is_string($processingKey)) {
                continue;
            }

            $recovered += $this->drain($processingKey);
        }

        return $recovered;
    }

    /**
     * Read a claimed buffer, insert it, and only then discard it.
     *
     * A short-lived lock keeps a concurrent run — most likely the every-minute
     * schedule overlapping itself — from inserting the same entries twice.
     */
    private function drain(string $processingKey): int
    {
        $redis = null;

        try {
            $redis = $this->connection();

            if (! $this->acquireClaim($redis, $processingKey)) {
                return 0;
            }

            if ((int) $redis->llen($processingKey) === 0) {
                $this->discard($redis, $processingKey);

                return 0;
            }

            $rows = $this->rowsFrom($redis->lrange($processingKey, 0, -1));

            if ($rows === []) {
                $this->discard($redis, $processingKey);

                return 0;
            }

            foreach (array_chunk($rows, TelemetryConfig::flushBatchSize()) as $chunk) {
                ApiRequestLog::query()->insert($chunk);
            }

            $this->discard($redis, $processingKey);

            return count($rows);
        } catch (\Throwable $exception) {
            $this->reportFailure('Failed to flush buffered API request logs.', $processingKey, $exception);

            // The key stays in the registry and the lock is left to expire, so
            // the next scheduled run retries instead of dropping the entries.
            $this->keepForRetry($redis, $processingKey);

            return 0;
        }
    }

    /**
     * @param  mixed  $entries
     * @return array<int, array<string, mixed>>
     */
    private function rowsFrom(mixed $entries): array
    {
        if (! is_array($entries)) {
            return [];
        }

        $rows = [];

        foreach ($entries as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $decoded = json_decode($entry, true);

            if (! is_array($decoded)) {
                continue;
            }

            $row = ApiRequestLogBuffer::prepareForInsert($decoded);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function acquireClaim(Connection $redis, string $processingKey): bool
    {
        // SET key 1 EX <ttl> NX — a single atomic call, so a crash can never
        // leave a lock behind that has no expiry.
        return (bool) $redis->command('set', [
            ApiRequestLogBuffer::lockKeyFor($processingKey),
            '1',
            'EX',
            self::CLAIM_TTL_SECONDS,
            'NX',
        ]);
    }

    private function discard(Connection $redis, string $processingKey): void
    {
        $redis->del($processingKey);
        $redis->del(ApiRequestLogBuffer::lockKeyFor($processingKey));
        $redis->srem(ApiRequestLogBuffer::processingRegistryKey(), $processingKey);
    }

    private function keepForRetry(?Connection $redis, string $processingKey): void
    {
        if ($redis === null) {
            return;
        }

        try {
            // renamenx carries over the original TTL, which may be close to
            // expiry; extend it so the retry window is not lost.
            $redis->expire($processingKey, TelemetryConfig::redisTtlSeconds());
        } catch (\Throwable) {
            //
        }
    }

    private function connection(): Connection
    {
        return $this->connection ??= Redis::connection(TelemetryConfig::redisConnection());
    }

    private function reportFailure(string $message, ?string $key, \Throwable $exception): void
    {
        Log::error($message, array_filter([
            'key' => $key,
            'exception' => $exception::class,
            'error' => $exception->getMessage(),
        ]));
    }
}
