<?php

namespace Systemverk\LaravelApiTelemetry\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Systemverk\LaravelApiTelemetry\Models\ApiUsageStat;
use Systemverk\LaravelApiTelemetry\Tests\TestCase;

class ConsolidateMonthlyApiUsageStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-01 04:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_sums_daily_rows_into_one_monthly_row_per_actor(): void
    {
        $this->daily('2026-06-01', 'guest', ['total_requests' => 10, 'responses_2xx' => 8, 'responses_5xx' => 2]);
        $this->daily('2026-06-02', 'guest', ['total_requests' => 5, 'responses_2xx' => 5]);
        $this->daily('2026-06-02', 'user:7', ['total_requests' => 3, 'responses_4xx' => 3], 7);

        $this->artisan('api-telemetry:consolidate-monthly', ['--month' => '2026-06'])->assertExitCode(0);

        $guest = ApiUsageStat::query()->monthly()->where('actor_key', 'guest')->firstOrFail();

        $this->assertSame(15, $guest->total_requests);
        $this->assertSame(13, $guest->responses_2xx);
        $this->assertSame(2, $guest->responses_5xx);

        $user = ApiUsageStat::query()->monthly()->where('actor_key', 'user:7')->firstOrFail();

        $this->assertSame(3, $user->total_requests);
        $this->assertSame(3, $user->responses_4xx);
        $this->assertSame(7, $user->user_id);
    }

    public function test_it_carries_every_status_bucket_across(): void
    {
        $this->daily('2026-06-01', 'guest', [
            'total_requests' => 5,
            'responses_1xx' => 1,
            'responses_2xx' => 1,
            'responses_3xx' => 1,
            'responses_4xx' => 1,
            'responses_5xx' => 1,
        ]);

        $this->artisan('api-telemetry:consolidate-monthly', ['--month' => '2026-06'])->assertExitCode(0);

        $stat = ApiUsageStat::query()->monthly()->firstOrFail();

        $this->assertSame(1, $stat->responses_1xx);
        $this->assertSame(1, $stat->responses_3xx);
    }

    public function test_it_ignores_days_outside_the_month(): void
    {
        $this->daily('2026-05-31', 'guest', ['total_requests' => 100]);
        $this->daily('2026-06-15', 'guest', ['total_requests' => 4]);
        $this->daily('2026-07-01', 'guest', ['total_requests' => 100]);

        $this->artisan('api-telemetry:consolidate-monthly', ['--month' => '2026-06'])->assertExitCode(0);

        $this->assertSame(4, ApiUsageStat::query()->monthly()->value('total_requests'));
    }

    public function test_it_never_folds_monthly_rows_back_into_themselves(): void
    {
        $this->daily('2026-06-01', 'guest', ['total_requests' => 4]);

        $this->artisan('api-telemetry:consolidate-monthly', ['--month' => '2026-06'])->assertExitCode(0);
        $this->artisan('api-telemetry:consolidate-monthly', ['--month' => '2026-06'])->assertExitCode(0);

        $this->assertSame(1, ApiUsageStat::query()->monthly()->count());
        $this->assertSame(4, ApiUsageStat::query()->monthly()->value('total_requests'));
    }

    public function test_it_defaults_to_the_previous_month(): void
    {
        $this->daily('2026-06-10', 'guest', ['total_requests' => 2]);

        $this->artisan('api-telemetry:consolidate-monthly')->assertExitCode(0);

        $this->assertSame('2026-06-01', ApiUsageStat::query()->monthly()->firstOrFail()->period_start->toDateString());
    }

    public function test_it_reports_when_there_is_nothing_to_consolidate(): void
    {
        $this->artisan('api-telemetry:consolidate-monthly', ['--month' => '2026-06'])
            ->expectsOutputToContain('No daily API usage stats found')
            ->assertExitCode(0);
    }

    public function test_it_rejects_an_invalid_month_option(): void
    {
        $this->artisan('api-telemetry:consolidate-monthly', ['--month' => '2026-13'])
            ->expectsOutputToContain('Invalid --month')
            ->assertExitCode(1);

        $this->artisan('api-telemetry:consolidate-monthly', ['--month' => 'june'])
            ->expectsOutputToContain('Invalid --month')
            ->assertExitCode(1);
    }

    public function test_it_does_nothing_when_the_package_is_disabled(): void
    {
        config()->set('api_telemetry.enabled', false);
        $this->daily('2026-06-01', 'guest', ['total_requests' => 4]);

        $this->artisan('api-telemetry:consolidate-monthly', ['--month' => '2026-06'])->assertExitCode(0);

        $this->assertSame(0, ApiUsageStat::query()->monthly()->count());
    }

    /**
     * @param  array<string, int>  $counters
     */
    private function daily(string $date, string $actorKey, array $counters, ?int $userId = null): void
    {
        ApiUsageStat::query()->create(array_merge([
            'period_type' => 'day',
            'period_start' => $date,
            'actor_key' => $actorKey,
            'user_id' => $userId,
        ], $counters));
    }
}
