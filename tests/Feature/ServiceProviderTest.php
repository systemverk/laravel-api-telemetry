<?php

namespace Systemverk\LaravelApiTelemetry\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Systemverk\LaravelApiTelemetry\Models\ApiRequestLog;
use Systemverk\LaravelApiTelemetry\Models\ApiUsageStat;
use Systemverk\LaravelApiTelemetry\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_registers_every_console_command(): void
    {
        $commands = array_keys($this->app()->make(\Illuminate\Contracts\Console\Kernel::class)->all());

        $this->assertContains('api-telemetry:flush', $commands);
        $this->assertContains('api-telemetry:consolidate-daily', $commands);
        $this->assertContains('api-telemetry:consolidate-monthly', $commands);
        $this->assertContains('api-telemetry:prune', $commands);
    }

    public function test_it_registers_the_scheduled_commands(): void
    {
        $expressions = collect($this->app()->make(Schedule::class)->events())
            ->mapWithKeys(fn ($event) => [$event->command => $event->expression]);

        $matching = $expressions->filter(fn ($_, $command) => str_contains((string) $command, 'api-telemetry:'));

        $this->assertCount(4, $matching);
        $this->assertTrue($matching->keys()->contains(fn ($command) => str_contains($command, 'api-telemetry:flush')));
    }

    public function test_the_migrations_create_both_tables(): void
    {
        $this->assertTrue(Schema::hasTable('api_request_logs'));
        $this->assertTrue(Schema::hasTable('api_usage_stats'));

        $this->assertTrue(Schema::hasColumns('api_usage_stats', [
            'responses_1xx', 'responses_2xx', 'responses_3xx', 'responses_4xx', 'responses_5xx',
        ]));
    }

    public function test_table_names_are_configurable(): void
    {
        config()->set('api_telemetry.database.tables.request_logs', 'tenant_request_logs');
        config()->set('api_telemetry.database.tables.usage_stats', 'tenant_usage_stats');

        $this->assertSame('tenant_request_logs', (new ApiRequestLog)->getTable());
        $this->assertSame('tenant_usage_stats', (new ApiUsageStat)->getTable());
    }

    public function test_the_database_connection_is_configurable(): void
    {
        $this->assertNull((new ApiRequestLog)->getConnectionName());

        config()->set('api_telemetry.database.connection', 'telemetry');

        $this->assertSame('telemetry', (new ApiRequestLog)->getConnectionName());
        $this->assertSame('telemetry', (new ApiUsageStat)->getConnectionName());
    }

    public function test_the_config_file_is_publishable(): void
    {
        $paths = \Illuminate\Support\ServiceProvider::pathsToPublish(
            \Systemverk\LaravelApiTelemetry\ApiTelemetryServiceProvider::class,
            'api-telemetry-config'
        );

        $this->assertNotEmpty($paths);
        $this->assertStringEndsWith('api_telemetry.php', (string) array_key_first($paths));
    }
}
