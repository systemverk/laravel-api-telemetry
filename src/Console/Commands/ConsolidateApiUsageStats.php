<?php

namespace Systemverk\LaravelApiTelemetry\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Systemverk\LaravelApiTelemetry\Models\ApiRequestLog;
use Systemverk\LaravelApiTelemetry\Models\ApiUsageStat;
use Systemverk\LaravelApiTelemetry\Support\ApiRequestLogBuffer;
use Systemverk\LaravelApiTelemetry\Support\TelemetryConfig;
use Systemverk\LaravelApiTelemetry\Support\UsageStatBucket;

class ConsolidateApiUsageStats extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api-telemetry:consolidate-daily
        {--date= : UTC date (Y-m-d), defaults to yesterday}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consolidate one day of raw API request logs into daily usage statistics';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! TelemetryConfig::enabled()) {
            return self::SUCCESS;
        }

        try {
            $date = $this->resolveDate();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        /** @var array<string, array<string, mixed>> $bucket */
        $bucket = [];
        $periodStart = $date->toDateString();
        $now = Carbon::now('UTC');

        ApiRequestLog::query()
            ->select(['id', 'user_id', 'status_code'])
            ->whereBetween('requested_at', [$date->startOfDay(), $date->endOfDay()])
            ->orderBy('id')
            ->chunkById(
                TelemetryConfig::consolidationChunkSize(),
                function (Collection $logs) use (&$bucket, $periodStart, $now): void {
                    foreach ($logs as $log) {
                        $actorKey = ApiRequestLogBuffer::actorKey($log->user_id);
                        $bucketKey = 'day|'.$periodStart.'|'.$actorKey;

                        $bucket[$bucketKey] ??= UsageStatBucket::make('day', $periodStart, $log->user_id, $actorKey, $now);

                        UsageStatBucket::countStatus($bucket[$bucketKey], (int) $log->status_code);
                    }
                }
            );

        if ($bucket === []) {
            $this->info('No API request logs found for consolidation window.');

            return self::SUCCESS;
        }

        ApiUsageStat::query()->upsert(
            array_values($bucket),
            ['period_type', 'period_start', 'actor_key'],
            UsageStatBucket::UPDATE_COLUMNS
        );

        $this->info('Consolidated '.count($bucket).' daily API usage stat rows.');

        return self::SUCCESS;
    }

    private function resolveDate(): CarbonImmutable
    {
        $dateOption = $this->option('date');

        if ($dateOption === null || (string) $dateOption === '') {
            return CarbonImmutable::now('UTC')->subDay();
        }

        $dateString = (string) $dateOption;

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString)) {
            throw new InvalidArgumentException('Invalid --date format. Expected Y-m-d, for example 2026-06-17.');
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $dateString, 'UTC');
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid --date value. Expected a real UTC date in Y-m-d format.');
        }

        if ($date->format('Y-m-d') !== $dateString) {
            throw new InvalidArgumentException('Invalid --date value. Expected a real UTC date in Y-m-d format.');
        }

        return $date;
    }
}
