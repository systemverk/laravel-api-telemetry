<?php

namespace Systemverk\LaravelApiTelemetry\Tests\Feature;

use Systemverk\LaravelApiTelemetry\Tests\TestCase;

class ConsolidationOptionValidationTest extends TestCase
{
    public function test_daily_consolidation_rejects_invalid_date_option(): void
    {
        $this->artisan('api-logs:consolidate-daily', ['--date' => '2026-99-01'])
            ->expectsOutputToContain('Invalid --date')
            ->assertExitCode(1);
    }

    public function test_monthly_consolidation_rejects_invalid_month_option(): void
    {
        $this->artisan('api-logs:consolidate-monthly', ['--month' => '2026-13'])
            ->expectsOutputToContain('Invalid --month')
            ->assertExitCode(1);
    }
}
