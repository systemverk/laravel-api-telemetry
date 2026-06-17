<?php

namespace Systemverk\LaravelApiTelemetry\Console\Commands;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Systemverk\LaravelApiTelemetry\Models\ApiUsageStat;

class ConsolidateMonthlyApiUsageStats extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api-logs:consolidate-monthly
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
        if (! config('api_request_logging.enabled', true)) {
            return self::SUCCESS;
        }

        try {
            $monthStart = $this->resolveMonthStart();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $monthEnd = $monthStart->endOfMonth();

        /** @phpstan-var array<string, array{period_type: 'month', period_start: string, user_id: int|null, actor_key: string, total_requests: int, responses_2xx: int, responses_4xx: int, responses_5xx: int, created_at: \Illuminate\Support\Carbon, updated_at: \Illuminate\Support\Carbon}> $bucket */
        $bucket = [];

        ApiUsageStat::query()
            ->where('period_type', 'day')
            ->whereBetween('period_start', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->orderBy('id')
            ->chunkById(1000, function (Collection $stats) use (&$bucket, $monthStart): void {
                foreach ($stats as $stat) {
                    $bucketKey = 'month|'.$monthStart->toDateString().'|'.$stat->actor_key;

                    if (! isset($bucket[$bucketKey])) {
                        $bucket[$bucketKey] = [
                            'period_type' => 'month',
                            'period_start' => $monthStart->toDateString(),
                            'user_id' => $stat->user_id,
                            'actor_key' => $stat->actor_key,
                            'total_requests' => 0,
                            'responses_2xx' => 0,
                            'responses_4xx' => 0,
                            'responses_5xx' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }

                    $bucket[$bucketKey]['total_requests'] += $stat->total_requests;
                    $bucket[$bucketKey]['responses_2xx'] += $stat->responses_2xx;
                    $bucket[$bucketKey]['responses_4xx'] += $stat->responses_4xx;
                    $bucket[$bucketKey]['responses_5xx'] += $stat->responses_5xx;
                }
            });

        if (empty($bucket)) {
            $this->info('No daily API usage stats found for monthly consolidation window.');

            return self::SUCCESS;
        }

        ApiUsageStat::query()->upsert(
            array_values($bucket),
            ['period_type', 'period_start', 'actor_key'],
            ['total_requests', 'responses_2xx', 'responses_4xx', 'responses_5xx', 'updated_at']
        );

        $this->info('Consolidated '.count($bucket).' monthly API usage stat rows.');

        return self::SUCCESS;
    }

    private function resolveMonthStart(): CarbonImmutable
    {
        $monthOption = $this->option('month');

        if ($monthOption !== null && (string) $monthOption !== '') {
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

        return CarbonImmutable::now('UTC')->subMonthNoOverflow()->startOfMonth();
    }
}
