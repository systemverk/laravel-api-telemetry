<?php

namespace Systemverk\LaravelApiTelemetry;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
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
        $this->mergeConfigFrom(__DIR__.'/../config/api_telemetry.php', 'api_telemetry');
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
                __DIR__.'/../config/api_telemetry.php' => config_path('api_telemetry.php'),
            ], 'api-telemetry-config');
        }

        $this->registerMiddleware();
        $this->registerSchedule();
    }

    /**
     * Append the request logging middleware to the "api" group.
     */
    private function registerMiddleware(): void
    {
        if (! config('api_telemetry.auto_register_middleware', true)) {
            return;
        }

        // Deliberately not guarded by runningInConsole(): Octane workers run
        // under the CLI SAPI and still serve HTTP requests.
        if (! $this->app->bound(HttpKernel::class)) {
            return;
        }

        $group = (string) config('api_telemetry.middleware_group', 'api');

        // bootstrap/app.php applies withMiddleware() through afterResolving on
        // the kernel, replacing the group list wholesale. Hooking the same event
        // — rather than app->booted() — guarantees we append after that, not
        // before, so our entry survives.
        $this->callAfterResolving(HttpKernel::class, function ($kernel) use ($group): void {
            // appendMiddlewareToGroup lives on the concrete kernel, not the
            // contract, so a custom kernel implementation is simply skipped.
            if (! $kernel instanceof FoundationHttpKernel) {
                return;
            }

            // The method throws for an unknown group, and an application that
            // never called withRouting(api: ...) has no "api" group at all.
            if (! array_key_exists($group, $kernel->getMiddlewareGroups())) {
                return;
            }

            $kernel->appendMiddlewareToGroup($group, LogApiRequest::class);
        });
    }

    /**
     * Register the flush, consolidation and prune commands on the scheduler.
     *
     * callAfterResolving means the scheduler is only touched if the application
     * actually builds one, so nothing is resolved during a normal web request.
     */
    private function registerSchedule(): void
    {
        if (! config('api_telemetry.schedule.enabled', true)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $flushMinutes = max(1, (int) config('api_telemetry.schedule.flush_minutes', 5));

            $schedule->command(FlushApiRequestLogs::class, ["--max-minutes={$flushMinutes}"])
                ->everyMinute()
                ->withoutOverlapping();

            $schedule->command(ConsolidateApiUsageStats::class)
                ->dailyAt((string) config('api_telemetry.schedule.daily_at', '02:00'))
                ->withoutOverlapping();

            $schedule->command(ConsolidateMonthlyApiUsageStats::class)
                ->monthlyOn(1, (string) config('api_telemetry.schedule.monthly_at', '03:00'))
                ->withoutOverlapping();

            $schedule->command(PruneApiRequestLogs::class)
                ->dailyAt((string) config('api_telemetry.schedule.prune_at', '03:10'))
                ->withoutOverlapping();
        });
    }
}
