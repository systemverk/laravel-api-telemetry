<?php

namespace Systemverk\LaravelApiTelemetry\Support;

/**
 * Typed accessors for the package configuration.
 *
 * Every read goes through here so that defaults stay in one place and the rest
 * of the package never has to deal with a missing or malformed config file.
 */
class TelemetryConfig
{
    public static function enabled(): bool
    {
        return (bool) config('api_telemetry.enabled', true);
    }

    public static function redisConnection(): string
    {
        $connection = config('api_telemetry.redis.connection', 'default');

        return is_string($connection) && $connection !== '' ? $connection : 'default';
    }

    public static function redisKeyPrefix(): string
    {
        $prefix = config('api_telemetry.redis.key_prefix', 'api_telemetry:');

        return is_string($prefix) ? $prefix : 'api_telemetry:';
    }

    public static function redisTtlSeconds(): int
    {
        return max(60, (int) config('api_telemetry.redis.ttl_seconds', 7200));
    }

    public static function databaseConnection(): ?string
    {
        $connection = config('api_telemetry.database.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public static function requestLogsTable(): string
    {
        $table = config('api_telemetry.database.tables.request_logs', 'api_request_logs');

        return is_string($table) && $table !== '' ? $table : 'api_request_logs';
    }

    public static function usageStatsTable(): string
    {
        $table = config('api_telemetry.database.tables.usage_stats', 'api_usage_stats');

        return is_string($table) && $table !== '' ? $table : 'api_usage_stats';
    }

    /**
     * @return 'integer'|'string'
     */
    public static function userIdType(): string
    {
        return config('api_telemetry.database.user_id_type') === 'string' ? 'string' : 'integer';
    }

    /**
     * @return array<int, string>
     */
    public static function exceptPaths(): array
    {
        $paths = config('api_telemetry.except', []);

        if (! is_array($paths)) {
            return [];
        }

        return array_values(array_filter($paths, is_string(...)));
    }

    public static function samplingRate(): float
    {
        $rate = (float) config('api_telemetry.sampling_rate', 1.0);

        return max(0.0, min(1.0, $rate));
    }

    public static function hashIps(): bool
    {
        return (bool) config('api_telemetry.privacy.hash_ips', true);
    }

    public static function ipHashSalt(): string
    {
        $salt = config('api_telemetry.privacy.ip_hash_salt');

        if (is_string($salt) && $salt !== '') {
            return $salt;
        }

        return (string) config('app.key');
    }

    public static function recordUserAgent(): bool
    {
        return (bool) config('api_telemetry.privacy.record_user_agent', true);
    }

    /**
     * @return array<int, string>
     */
    public static function requestIdHeaders(): array
    {
        $headers = config('api_telemetry.request_id_headers', ['X-Request-Id', 'X-Correlation-Id']);

        if (! is_array($headers)) {
            return [];
        }

        return array_values(array_filter($headers, is_string(...)));
    }

    public static function flushBatchSize(): int
    {
        return max(1, (int) config('api_telemetry.flush_batch_size', 1000));
    }

    public static function consolidationChunkSize(): int
    {
        return max(100, (int) config('api_telemetry.consolidation_chunk_size', 2000));
    }

    public static function retentionDays(): int
    {
        return max(1, (int) config('api_telemetry.retention_days', 90));
    }
}
