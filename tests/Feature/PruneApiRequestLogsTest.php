<?php

namespace Systemverk\LaravelApiTelemetry\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Systemverk\LaravelApiTelemetry\Models\ApiRequestLog;
use Systemverk\LaravelApiTelemetry\Tests\TestCase;

class PruneApiRequestLogsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-18 04:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_deletes_logs_older_than_the_retention_window(): void
    {
        config()->set('api_telemetry.retention_days', 30);

        $this->log('2026-04-01 00:00:00');
        $this->log('2026-06-01 00:00:00');
        $this->log('2026-06-17 00:00:00');

        $this->artisan('api-telemetry:prune')
            ->expectsOutputToContain('Pruned 1 API request logs older than 30 days.')
            ->assertExitCode(0);

        $this->assertSame(2, ApiRequestLog::query()->count());
    }

    public function test_the_days_option_overrides_the_configured_retention(): void
    {
        config()->set('api_telemetry.retention_days', 365);

        $this->log('2026-06-01 00:00:00');
        $this->log('2026-06-17 00:00:00');

        $this->artisan('api-telemetry:prune', ['--days' => 7])->assertExitCode(0);

        $this->assertSame(1, ApiRequestLog::query()->count());
    }

    public function test_retention_is_never_shorter_than_one_day(): void
    {
        $this->log('2026-06-18 03:00:00');

        $this->artisan('api-telemetry:prune', ['--days' => 0])
            ->expectsOutputToContain('older than 1 days')
            ->assertExitCode(0);

        $this->assertSame(1, ApiRequestLog::query()->count());
    }

    public function test_it_deletes_a_large_backlog_across_several_chunks(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->log('2026-01-01 00:00:00');
        }

        $this->artisan('api-telemetry:prune', ['--days' => 30, '--chunk' => 10])
            ->expectsOutputToContain('Pruned 25 API request logs')
            ->assertExitCode(0);

        $this->assertSame(0, ApiRequestLog::query()->count());
    }

    public function test_it_does_nothing_when_the_package_is_disabled(): void
    {
        config()->set('api_telemetry.enabled', false);
        $this->log('2026-01-01 00:00:00');

        $this->artisan('api-telemetry:prune')->assertExitCode(0);

        $this->assertSame(1, ApiRequestLog::query()->count());
    }

    private function log(string $requestedAt): void
    {
        ApiRequestLog::query()->create([
            'requested_at' => $requestedAt,
            'method' => 'GET',
            'path' => '/api/orders',
            'status_code' => 200,
            'duration_ms' => 10,
        ]);
    }
}
