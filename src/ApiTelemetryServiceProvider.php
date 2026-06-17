<?php

namespace Systemverk\LaravelApiTelemetry;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\ServiceProvider;
use Systemverk\LaravelApiTelemetry\Console\Commands\ConsolidateApiUsageStats;
use Systemverk\LaravelApiTelemetry\Console\Commands\ConsolidateMonthlyApiUsageStats;
use Systemverk\LaravelApiTelemetry\Console\Commands\FlushApiRequestLogs;
use Systemverk\LaravelApiTelemetry\Console\Commands\PruneApiRequestLogs;
use Systemverk\LaravelApiTelemetry\Http\Middleware\LogApiRequest;

class ApiTelemetryServiceProvider extends ServiceProvider
{
    /**
     * Register package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/api_request_logging.php', 'api_request_logging');
    }

    /**
     * Bootstrap package services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                FlushApiRequestLogs::class,
                ConsolidateApiUsageStats::class,
                ConsolidateMonthlyApiUsageStats::class,
                PruneApiRequestLogs::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/api_request_logging.php' => config_path('api_request_logging.php'),
            ], 'api-telemetry-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'api-telemetry-migrations');
        }

        $this->registerMiddleware();
        $this->registerSchedule();
    }

    /**
     * Append the request logging middleware to the "api" group.
     */
    private function registerMiddleware(): void
    {
        if (! config('api_request_logging.auto_register_middleware', true)) {
            return;
        }

        $this->app->booted(function (): void {
            $this->app->make(HttpKernel::class)
                ->appendMiddlewareToGroup('api', LogApiRequest::class);
        });
    }

    /**
     * Register the flush, consolidation and prune commands on the scheduler.
     */
    private function registerSchedule(): void
    {
        if (! config('api_request_logging.schedule.enabled', true)) {
            return;
        }

        $this->app->booted(function (): void {
            $schedule = $this->app->make(Schedule::class);
            $config = config('api_request_logging.schedule');

            $schedule->command('api-logs:flush --max-minutes='.$config['flush_minutes'])->everyMinute();
            $schedule->command('api-logs:consolidate-daily')->dailyAt($config['daily_at']);
            $schedule->command('api-logs:consolidate-monthly')->monthlyOn(1, $config['monthly_at']);
            $schedule->command('api-logs:prune')->dailyAt($config['prune_at']);
        });
    }
}
