<?php

namespace Systemverk\LaravelApiTelemetry\Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Systemverk\LaravelApiTelemetry\Models\ApiRequestLog;
use Systemverk\LaravelApiTelemetry\Support\ApiRequestLogBuffer;
use Systemverk\LaravelApiTelemetry\Tests\Support\FakeRedisConnection;
use Systemverk\LaravelApiTelemetry\Tests\TestCase;

class FlushApiRequestLogsTest extends TestCase
{
    use RefreshDatabase;

    private FakeRedisConnection $redis;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-17 14:35:00', 'UTC'));
        $this->redis = $this->fakeRedis();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_writes_buffered_entries_to_the_database(): void
    {
        $this->buffer(ApiRequestLogBuffer::currentMinuteKey(), [
            $this->entry(['path' => '/api/orders', 'status_code' => 200]),
            $this->entry(['path' => '/api/orders/1', 'status_code' => 404]),
        ]);

        $this->artisan('api-telemetry:flush')
            ->expectsOutputToContain('Flushed 2 API request logs.')
            ->assertExitCode(0);

        $this->assertSame(2, ApiRequestLog::query()->count());
        $this->assertSame('/api/orders', ApiRequestLog::query()->orderBy('id')->first()?->path);
    }

    public function test_it_scans_the_configured_number_of_minutes_backwards(): void
    {
        $this->buffer(ApiRequestLogBuffer::keyForMinute(Carbon::now('UTC')->subMinutes(3)), [$this->entry()]);
        $this->buffer(ApiRequestLogBuffer::keyForMinute(Carbon::now('UTC')->subMinutes(9)), [$this->entry()]);

        $this->artisan('api-telemetry:flush', ['--max-minutes' => 5])->assertExitCode(0);

        $this->assertSame(1, ApiRequestLog::query()->count());

        $this->artisan('api-telemetry:flush', ['--max-minutes' => 15])->assertExitCode(0);

        $this->assertSame(2, ApiRequestLog::query()->count());
    }

    public function test_it_removes_the_buffer_after_a_successful_write(): void
    {
        $key = ApiRequestLogBuffer::currentMinuteKey();
        $this->buffer($key, [$this->entry()]);

        $this->artisan('api-telemetry:flush')->assertExitCode(0);

        $this->assertArrayNotHasKey($key, $this->redis->store);
        $this->assertSame([], $this->redis->smembers(ApiRequestLogBuffer::processingRegistryKey()));
    }

    public function test_a_buffer_that_expires_mid_flush_does_not_crash_the_command(): void
    {
        // Reproduces the predis "ERR no such key" race: llen sees entries, the
        // key expires, and renamenx then fails.
        $key = ApiRequestLogBuffer::currentMinuteKey();
        $this->buffer($key, [$this->entry()]);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'Failed to claim'));

        $this->redis->failOn('renamenx', new \RuntimeException('ERR no such key'));

        $this->artisan('api-telemetry:flush')
            ->expectsOutputToContain('Flushed 0 API request logs.')
            ->assertExitCode(0);
    }

    public function test_malformed_entries_are_skipped_without_losing_the_rest(): void
    {
        $this->buffer(ApiRequestLogBuffer::currentMinuteKey(), [
            'not json at all',
            json_encode(['method' => 'GET']),
            $this->entry(['path' => '/api/good']),
        ]);

        $this->artisan('api-telemetry:flush')->assertExitCode(0);

        $this->assertSame(1, ApiRequestLog::query()->count());
        $this->assertSame('/api/good', ApiRequestLog::query()->first()?->path);
    }

    public function test_a_buffer_containing_only_junk_is_discarded(): void
    {
        $key = ApiRequestLogBuffer::currentMinuteKey();
        $this->buffer($key, ['garbage']);

        $this->artisan('api-telemetry:flush')->assertExitCode(0);

        $this->assertSame(0, ApiRequestLog::query()->count());
        $this->assertArrayNotHasKey($key, $this->redis->store);
        $this->assertSame([], $this->redis->smembers(ApiRequestLogBuffer::processingRegistryKey()));
    }

    public function test_a_failed_write_is_retried_on_the_next_run_instead_of_being_dropped(): void
    {
        Log::shouldReceive('error')->once();

        $this->buffer(ApiRequestLogBuffer::currentMinuteKey(), [$this->entry(), $this->entry()]);

        $this->redis->failOn('lrange', new \RuntimeException('read timeout'));

        $this->artisan('api-telemetry:flush')->assertExitCode(0);

        $this->assertSame(0, ApiRequestLog::query()->count());

        $registry = $this->redis->smembers(ApiRequestLogBuffer::processingRegistryKey());
        $this->assertCount(1, $registry, 'The claimed buffer must stay registered for recovery.');

        // The claim lock blocks an immediate retry; release it as its TTL would.
        $this->redis->del(ApiRequestLogBuffer::lockKeyFor($registry[0]));

        $this->artisan('api-telemetry:flush')
            ->expectsOutputToContain('2 recovered from a previous run')
            ->assertExitCode(0);

        $this->assertSame(2, ApiRequestLog::query()->count());
        $this->assertSame([], $this->redis->smembers(ApiRequestLogBuffer::processingRegistryKey()));
    }

    public function test_a_claimed_buffer_is_not_processed_twice_while_locked(): void
    {
        $this->buffer(ApiRequestLogBuffer::currentMinuteKey(), [$this->entry()]);

        $this->artisan('api-telemetry:flush')->assertExitCode(0);
        $this->artisan('api-telemetry:flush')->assertExitCode(0);

        $this->assertSame(1, ApiRequestLog::query()->count());
    }

    public function test_an_expired_orphan_is_cleaned_out_of_the_registry(): void
    {
        $orphan = ApiRequestLogBuffer::currentMinuteKey().':processing:gone';
        $this->redis->sadd(ApiRequestLogBuffer::processingRegistryKey(), [$orphan]);

        $this->artisan('api-telemetry:flush')->assertExitCode(0);

        $this->assertSame([], $this->redis->smembers(ApiRequestLogBuffer::processingRegistryKey()));
    }

    public function test_it_does_nothing_when_the_package_is_disabled(): void
    {
        config()->set('api_telemetry.enabled', false);

        $this->buffer(ApiRequestLogBuffer::currentMinuteKey(), [$this->entry()]);

        $this->artisan('api-telemetry:flush')->assertExitCode(0);

        $this->assertSame(0, ApiRequestLog::query()->count());
    }

    public function test_it_inserts_in_batches_of_the_configured_size(): void
    {
        config()->set('api_telemetry.flush_batch_size', 2);

        $entries = [];

        for ($i = 0; $i < 5; $i++) {
            $entries[] = $this->entry(['path' => "/api/orders/{$i}"]);
        }

        $this->buffer(ApiRequestLogBuffer::currentMinuteKey(), $entries);

        $inserts = 0;
        DB::listen(function (QueryExecuted $query) use (&$inserts): void {
            if (str_starts_with(strtolower(trim($query->sql)), 'insert')) {
                $inserts++;
            }
        });

        $this->artisan('api-telemetry:flush')->assertExitCode(0);

        $this->assertSame(5, ApiRequestLog::query()->count());
        $this->assertSame(3, $inserts, 'Five rows at a batch size of two should take three inserts.');
    }

    /**
     * @param  array<int, string>  $entries
     */
    private function buffer(string $key, array $entries): void
    {
        $this->redis->rpush($key, ...$entries);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function entry(array $overrides = []): string
    {
        return (string) json_encode(array_merge([
            'requested_at' => Carbon::now('UTC')->toDateTimeString(),
            'method' => 'GET',
            'path' => '/api/orders',
            'route_name' => null,
            'status_code' => 200,
            'duration_ms' => 12,
            'user_id' => null,
            'ip_hash' => null,
            'user_agent' => null,
            'request_id' => null,
        ], $overrides));
    }
}
