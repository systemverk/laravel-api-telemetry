<?php

namespace Systemverk\LaravelApiTelemetry\Console\Commands;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Systemverk\LaravelApiTelemetry\Models\ApiRequestLog;
use Systemverk\LaravelApiTelemetry\Models\ApiUsageStat;

class ConsolidateApiUsageStats extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api-logs:consolidate-daily
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
        if (! config('api_request_logging.enabled', true)) {
            return self::SUCCESS;
        }

        try {
            $date = $this->resolveDate();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        /** @phpstan-var array<string, array{period_type: 'day', period_start: string, user_id: int|null, actor_key: string, total_requests: int, responses_2xx: int, responses_4xx: int, responses_5xx: int, created_at: \Illuminate\Support\Carbon, updated_at: \Illuminate\Support\Carbon}> $bucket */
        $bucket = [];
        $chunkSize = max(100, (int) config('api_request_logging.consolidation_chunk_size', 2000));

        ApiRequestLog::query()
            ->select(['id', 'requested_at', 'user_id', 'status_code'])
            ->whereBetween('requested_at', [$date->startOfDay(), $date->endOfDay()])
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $logs) use (&$bucket): void {
                foreach ($logs as $log) {
                    $periodStart = CarbonImmutable::instance($log->requested_at)->startOfDay();
                    $actorKey = $log->user_id === null ? 'guest' : 'user:'.$log->user_id;
                    $bucketKey = 'day|'.$periodStart->toDateString().'|'.$actorKey;

                    if (! isset($bucket[$bucketKey])) {
                        $bucket[$bucketKey] = [
                            'period_type' => 'day',
                            'period_start' => $periodStart->toDateString(),
                            'user_id' => $log->user_id,
                            'actor_key' => $actorKey,
                            'total_requests' => 0,
                            'responses_2xx' => 0,
                            'responses_4xx' => 0,
                            'responses_5xx' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }

                    $bucket[$bucketKey]['total_requests']++;

                    if ($log->status_code >= 200 && $log->status_code < 300) {
                        $bucket[$bucketKey]['responses_2xx']++;
                    } elseif ($log->status_code >= 400 && $log->status_code < 500) {
                        $bucket[$bucketKey]['responses_4xx']++;
                    } elseif ($log->status_code >= 500 && $log->status_code < 600) {
                        $bucket[$bucketKey]['responses_5xx']++;
                    }
                }
            });

        if (empty($bucket)) {
            $this->info('No API request logs found for consolidation window.');

            return self::SUCCESS;
        }

        ApiUsageStat::query()->upsert(
            array_values($bucket),
            ['period_type', 'period_start', 'actor_key'],
            ['total_requests', 'responses_2xx', 'responses_4xx', 'responses_5xx', 'updated_at']
        );

        $this->info('Consolidated '.count($bucket).' daily API usage stat rows.');

        return self::SUCCESS;
    }

    private function resolveDate(): CarbonImmutable
    {
        $dateOption = $this->option('date');

        if ($dateOption !== null && (string) $dateOption !== '') {
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

        return CarbonImmutable::now('UTC')->subDay();
    }
}
