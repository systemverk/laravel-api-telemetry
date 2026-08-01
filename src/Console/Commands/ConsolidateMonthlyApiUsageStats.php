<?php

namespace Systemverk\LaravelApiTelemetry\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Systemverk\LaravelApiTelemetry\Models\ApiUsageStat;
use Systemverk\LaravelApiTelemetry\Support\TelemetryConfig;
use Systemverk\LaravelApiTelemetry\Support\UsageStatBucket;

class ConsolidateMonthlyApiUsageStats extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api-telemetry:consolidate-monthly
        {--month= : UTC month (Y-m), defaults to previous month}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consolidate daily API usage statistics into monthly statistics';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! TelemetryConfig::enabled()) {
            return self::SUCCESS;
        }

        try {
            $monthStart = $this->resolveMonthStart();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $monthEnd = $monthStart->endOfMonth();
        $periodStart = $monthStart->toDateString();
        $now = Carbon::now('UTC');

        /** @var array<string, array<string, mixed>> $bucket */
        $bucket = [];

        ApiUsageStat::query()
            ->where('period_type', 'day')
            ->whereBetween('period_start', [$periodStart, $monthEnd->toDateString()])
            ->orderBy('id')
            ->chunkById(
                TelemetryConfig::consolidationChunkSize(),
                function (Collection $stats) use (&$bucket, $periodStart, $now): void {
                    foreach ($stats as $stat) {
                        $bucketKey = 'month|'.$periodStart.'|'.$stat->actor_key;

                        $bucket[$bucketKey] ??= UsageStatBucket::make('month', $periodStart, $stat->user_id, $stat->actor_key, $now);

                        UsageStatBucket::addCounters($bucket[$bucketKey], $stat);
                    }
                }
            );

        if ($bucket === []) {
            $this->info('No daily API usage stats found for monthly consolidation window.');

            return self::SUCCESS;
        }

        ApiUsageStat::query()->upsert(
            array_values($bucket),
            ['period_type', 'period_start', 'actor_key'],
            UsageStatBucket::UPDATE_COLUMNS
        );

        $this->info('Consolidated '.count($bucket).' monthly API usage stat rows.');

        return self::SUCCESS;
    }

    private function resolveMonthStart(): CarbonImmutable
    {
        $monthOption = $this->option('month');

        if ($monthOption === null || (string) $monthOption === '') {
            return CarbonImmutable::now('UTC')->subMonthNoOverflow()->startOfMonth();
        }

        $monthString = (string) $monthOption;

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthString)) {
            throw new InvalidArgumentException('Invalid --month format. Expected Y-m, for example 2026-06.');
        }

        try {
            $monthStart = CarbonImmutable::createFromFormat('!Y-m', $monthString, 'UTC');
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid --month value. Expected a real UTC month in Y-m format.');
        }

        if ($monthStart->format('Y-m') !== $monthString) {
            throw new InvalidArgumentException('Invalid --month value. Expected a real UTC month in Y-m format.');
        }

        return $monthStart->startOfMonth();
    }
}
