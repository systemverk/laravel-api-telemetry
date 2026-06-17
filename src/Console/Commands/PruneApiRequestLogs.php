<?php

namespace Systemverk\LaravelApiTelemetry\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Systemverk\LaravelApiTelemetry\Models\ApiRequestLog;

class PruneApiRequestLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api-logs:prune {--days= : Override retention in days}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete raw API request logs older than retention window';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! config('api_request_logging.enabled', true)) {
            return self::SUCCESS;
        }

        $retentionDays = (int) ($this->option('days') ?? config('api_request_logging.retention_days', 90));
        $retentionDays = max(1, $retentionDays);

        $cutoff = CarbonImmutable::now()->utc()->subDays($retentionDays);

        $deleted = ApiRequestLog::query()
            ->where('requested_at', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$deleted} API request logs older than {$retentionDays} days.");

        return self::SUCCESS;
    }
}
