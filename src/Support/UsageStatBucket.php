<?php

namespace Systemverk\LaravelApiTelemetry\Support;

use Illuminate\Support\Carbon;

/**
 * Shared shape of an aggregated usage-stat row.
 *
 * Both consolidation commands build the same rows — one from raw request logs,
 * one from daily stats — so the column list lives in a single place.
 */
final class UsageStatBucket
{
    /**
     * Counter columns, in the order they are reported.
     */
    public const COUNTERS = [
        'total_requests',
        'responses_1xx',
        'responses_2xx',
        'responses_3xx',
        'responses_4xx',
        'responses_5xx',
    ];

    /**
     * Columns refreshed when an existing period/actor row is upserted.
     */
    public const UPDATE_COLUMNS = [
        'total_requests',
        'responses_1xx',
        'responses_2xx',
        'responses_3xx',
        'responses_4xx',
        'responses_5xx',
        'user_id',
        'updated_at',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function make(string $periodType, string $periodStart, int|string|null $userId, string $actorKey, Carbon $now): array
    {
        return [
            'period_type' => $periodType,
            'period_start' => $periodStart,
            'user_id' => $userId,
            'actor_key' => $actorKey,
            'total_requests' => 0,
            'responses_1xx' => 0,
            'responses_2xx' => 0,
            'responses_3xx' => 0,
            'responses_4xx' => 0,
            'responses_5xx' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Increment the total and the bucket matching the status code.
     *
     * Codes outside 100-599 still count towards the total so that it always
     * reflects the number of requests, even when a class bucket is missing.
     *
     * @param  array<string, mixed>  $bucket
     */
    public static function countStatus(array &$bucket, int $statusCode): void
    {
        $bucket['total_requests']++;

        $class = intdiv($statusCode, 100);

        $column = match ($class) {
            1 => 'responses_1xx',
            2 => 'responses_2xx',
            3 => 'responses_3xx',
            4 => 'responses_4xx',
            5 => 'responses_5xx',
            default => null,
        };

        if ($column !== null) {
            $bucket[$column]++;
        }
    }

    /**
     * Add one already-aggregated row into a bucket.
     *
     * @param  array<string, mixed>  $bucket
     */
    public static function addCounters(array &$bucket, object $stat): void
    {
        foreach (self::COUNTERS as $column) {
            $bucket[$column] += (int) ($stat->{$column} ?? 0);
        }
    }
}
