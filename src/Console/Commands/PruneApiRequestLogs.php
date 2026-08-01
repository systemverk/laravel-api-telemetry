<?php

namespace Systemverk\LaravelApiTelemetry\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Systemverk\LaravelApiTelemetry\Models\ApiRequestLog;
use Systemverk\LaravelApiTelemetry\Support\TelemetryConfig;

class PruneApiRequestLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api-telemetry:prune
        {--days= : Override retention in days}
        {--chunk=1000 : Rows deleted per statement}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete raw API request logs older than the retention window';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! TelemetryConfig::enabled()) {
            return self::SUCCESS;
        }

        $daysOption = $this->option('days');

        $retentionDays = $daysOption === null || (string) $daysOption === ''
            ? TelemetryConfig::retentionDays()
            : max(1, (int) $daysOption);

        $chunk = max(1, (int) $this->option('chunk'));
        $cutoff = CarbonImmutable::now('UTC')->subDays($retentionDays);

        // Deleted in chunks so a large backlog does not hold a single long
        // transaction open or exhaust the database's undo/binlog space.
        $deleted = 0;

        do {
            $affected = ApiRequestLog::query()
                ->where('requested_at', '<', $cutoff)
                ->limit($chunk)
                ->delete();

            $deleted += $affected;
        } while ($affected > 0);

        $this->info("Pruned {$deleted} API request logs older than {$retentionDays} days.");

        return self::SUCCESS;
    }
}
