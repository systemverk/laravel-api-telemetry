<?php

namespace Systemverk\LaravelApiTelemetry\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Systemverk\LaravelApiTelemetry\Models\ApiRequestLog;
use Systemverk\LaravelApiTelemetry\Models\ApiUsageStat;
use Systemverk\LaravelApiTelemetry\Tests\TestCase;

class ConsolidateApiUsageStatsTest extends TestCase
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

    public function test_it_counts_every_status_class_separately(): void
    {
        foreach ([100, 200, 201, 301, 404, 422, 500, 503] as $status) {
            $this->log(['status_code' => $status]);
        }

        $this->artisan('api-telemetry:consolidate-daily', ['--date' => '2026-06-17'])->assertExitCode(0);

        $stat = ApiUsageStat::query()->daily()->firstOrFail();

        $this->assertSame(8, $stat->total_requests);
        $this->assertSame(1, $stat->responses_1xx);
        $this->assertSame(2, $stat->responses_2xx);
        $this->assertSame(1, $stat->responses_3xx);
        $this->assertSame(2, $stat->responses_4xx);
        $this->assertSame(2, $stat->responses_5xx);
    }

    public function test_the_total_equals_the_sum_of_the_status_buckets(): void
    {
        foreach ([100, 204, 302, 400, 500] as $status) {
            $this->log(['status_code' => $status]);
        }

        $this->artisan('api-telemetry:consolidate-daily', ['--date' => '2026-06-17'])->assertExitCode(0);

        $stat = ApiUsageStat::query()->daily()->firstOrFail();

        $this->assertSame(
            $stat->total_requests,
            $stat->responses_1xx + $stat->responses_2xx + $stat->responses_3xx + $stat->responses_4xx + $stat->responses_5xx
        );
    }

    public function test_it_separates_guests_from_authenticated_actors(): void
    {
        $this->log(['user_id' => null]);
        $this->log(['user_id' => null]);
        $this->log(['user_id' => 7]);

        $this->artisan('api-telemetry:consolidate-daily', ['--date' => '2026-06-17'])->assertExitCode(0);

        $this->assertSame(2, ApiUsageStat::query()->where('actor_key', 'guest')->value('total_requests'));
        $this->assertSame(1, ApiUsageStat::query()->where('actor_key', 'user:7')->value('total_requests'));
    }

    public function test_reruns_are_idempotent(): void
    {
        $this->log();
        $this->log();

        $this->artisan('api-telemetry:consolidate-daily', ['--date' => '2026-06-17'])->assertExitCode(0);
        $this->artisan('api-telemetry:consolidate-daily', ['--date' => '2026-06-17'])->assertExitCode(0);

        $this->assertSame(1, ApiUsageStat::query()->count());
        $this->assertSame(2, ApiUsageStat::query()->value('total_requests'));
    }

    public function test_a_rerun_reflects_logs_that_arrived_late(): void
    {
        $this->log();
        $this->artisan('api-telemetry:consolidate-daily', ['--date' => '2026-06-17'])->assertExitCode(0);

        $this->log();
        $this->artisan('api-telemetry:consolidate-daily', ['--date' => '2026-06-17'])->assertExitCode(0);

        $this->assertSame(1, ApiUsageStat::query()->count());
        $this->assertSame(2, ApiUsageStat::query()->value('total_requests'));
    }

    public function test_it_only_consolidates_the_requested_day(): void
    {
        $this->log(['requested_at' => '2026-06-16 23:59:59']);
        $this->log(['requested_at' => '2026-06-17 00:00:00']);
        $this->log(['requested_at' => '2026-06-17 23:59:59']);
        $this->log(['requested_at' => '2026-06-18 00:00:00']);

        $this->artisan('api-telemetry:consolidate-daily', ['--date' => '2026-06-17'])->assertExitCode(0);

        $this->assertSame(2, ApiUsageStat::query()->value('total_requests'));
    }

    public function test_it_defaults_to_yesterday(): void
    {
        $this->log(['requested_at' => '2026-06-17 10:00:00']);

        $this->artisan('api-telemetry:consolidate-daily')->assertExitCode(0);

        $this->assertSame('2026-06-17', ApiUsageStat::query()->firstOrFail()->period_start->toDateString());
    }

    public function test_it_reports_when_there_is_nothing_to_consolidate(): void
    {
        $this->artisan('api-telemetry:consolidate-daily', ['--date' => '2026-06-17'])
            ->expectsOutputToContain('No API request logs found')
            ->assertExitCode(0);

        $this->assertSame(0, ApiUsageStat::query()->count());
    }

    public function test_it_processes_more_rows_than_one_chunk(): void
    {
        config()->set('api_telemetry.consolidation_chunk_size', 100);

        for ($i = 0; $i < 250; $i++) {
            $this->log(['status_code' => $i % 2 === 0 ? 200 : 500]);
        }

        $this->artisan('api-telemetry:consolidate-daily', ['--date' => '2026-06-17'])->assertExitCode(0);

        $stat = ApiUsageStat::query()->firstOrFail();

        $this->assertSame(250, $stat->total_requests);
        $this->assertSame(125, $stat->responses_2xx);
        $this->assertSame(125, $stat->responses_5xx);
    }

    public function test_it_rejects_an_invalid_date_option(): void
    {
        $this->artisan('api-telemetry:consolidate-daily', ['--date' => '2026-99-01'])
            ->expectsOutputToContain('Invalid --date')
            ->assertExitCode(1);

        $this->artisan('api-telemetry:consolidate-daily', ['--date' => 'yesterday'])
            ->expectsOutputToContain('Invalid --date')
            ->assertExitCode(1);

        $this->artisan('api-telemetry:consolidate-daily', ['--date' => '2026-02-30'])
            ->expectsOutputToContain('Invalid --date')
            ->assertExitCode(1);
    }

    public function test_it_does_nothing_when_the_package_is_disabled(): void
    {
        config()->set('api_telemetry.enabled', false);
        $this->log();

        $this->artisan('api-telemetry:consolidate-daily', ['--date' => '2026-06-17'])->assertExitCode(0);

        $this->assertSame(0, ApiUsageStat::query()->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function log(array $overrides = []): ApiRequestLog
    {
        return ApiRequestLog::query()->create(array_merge([
            'requested_at' => '2026-06-17 12:00:00',
            'method' => 'GET',
            'path' => '/api/orders',
            'status_code' => 200,
            'duration_ms' => 10,
            'user_id' => null,
        ], $overrides));
    }
}
