<?php

namespace Systemverk\LaravelApiTelemetry\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;
use Systemverk\LaravelApiTelemetry\Support\ApiRequestLogBuffer;
use Systemverk\LaravelApiTelemetry\Tests\TestCase;

class ApiRequestLogBufferTest extends TestCase
{
    public function test_it_builds_the_minute_key_from_the_configured_prefix(): void
    {
        config()->set('api_telemetry.redis.key_prefix', 'tenant-a:');

        Carbon::setTestNow(Carbon::parse('2026-06-17 14:35:59', 'UTC'));

        $this->assertSame('tenant-a:requests:202606171435', ApiRequestLogBuffer::currentMinuteKey());

        Carbon::setTestNow();
    }

    public function test_the_minute_key_is_always_derived_from_utc(): void
    {
        config()->set('app.timezone', 'Europe/Oslo');
        date_default_timezone_set('Europe/Oslo');

        Carbon::setTestNow(Carbon::parse('2026-06-17 14:35:00', 'UTC'));

        $this->assertStringEndsWith('202606171435', ApiRequestLogBuffer::currentMinuteKey());

        Carbon::setTestNow();
        date_default_timezone_set('UTC');
    }

    public function test_it_normalizes_a_request_into_a_payload(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 14:35:00', 'UTC'));

        $request = Request::create('/api/orders/42', 'POST');
        $request->headers->set('User-Agent', 'PostmanRuntime/7.36.0');
        $request->headers->set('X-Request-Id', 'f0e1d2c3-b4a5-4967-8899-aabbccddeeff');
        $request->server->set('REMOTE_ADDR', '203.0.113.7');

        $payload = ApiRequestLogBuffer::normalizePayload($request, new Response('', 201), microtime(true));

        $this->assertSame('2026-06-17 14:35:00', $payload['requested_at']);
        $this->assertSame('POST', $payload['method']);
        $this->assertSame('/api/orders/42', $payload['path']);
        $this->assertSame(201, $payload['status_code']);
        $this->assertNull($payload['user_id']);
        $this->assertSame('PostmanRuntime/7.36.0', $payload['user_agent']);
        $this->assertSame('f0e1d2c3-b4a5-4967-8899-aabbccddeeff', $payload['request_id']);
        $this->assertGreaterThanOrEqual(0, $payload['duration_ms']);

        Carbon::setTestNow();
    }

    public function test_it_hashes_the_ip_address_and_never_stores_it_verbatim(): void
    {
        $request = Request::create('/api/ping');
        $request->server->set('REMOTE_ADDR', '203.0.113.7');

        $payload = ApiRequestLogBuffer::normalizePayload($request, new Response, microtime(true));

        $this->assertNotNull($payload['ip_hash']);
        $this->assertSame(64, strlen((string) $payload['ip_hash']));
        $this->assertStringNotContainsString('203.0.113.7', json_encode($payload) ?: '');
    }

    public function test_the_ip_hash_uses_the_configured_salt(): void
    {
        $request = Request::create('/api/ping');
        $request->server->set('REMOTE_ADDR', '203.0.113.7');

        config()->set('api_telemetry.privacy.ip_hash_salt', 'salt-one');
        $first = ApiRequestLogBuffer::normalizePayload($request, new Response, microtime(true))['ip_hash'];

        config()->set('api_telemetry.privacy.ip_hash_salt', 'salt-two');
        $second = ApiRequestLogBuffer::normalizePayload($request, new Response, microtime(true))['ip_hash'];

        $this->assertNotSame($first, $second);
    }

    public function test_ip_recording_can_be_disabled_entirely(): void
    {
        config()->set('api_telemetry.privacy.hash_ips', false);

        $request = Request::create('/api/ping');
        $request->server->set('REMOTE_ADDR', '203.0.113.7');

        $payload = ApiRequestLogBuffer::normalizePayload($request, new Response, microtime(true));

        $this->assertNull($payload['ip_hash']);
    }

    public function test_user_agent_recording_can_be_disabled(): void
    {
        config()->set('api_telemetry.privacy.record_user_agent', false);

        $request = Request::create('/api/ping');
        $request->headers->set('User-Agent', 'curl/8.4.0');

        $payload = ApiRequestLogBuffer::normalizePayload($request, new Response, microtime(true));

        $this->assertNull($payload['user_agent']);
    }

    public function test_it_truncates_oversized_values_to_the_column_widths(): void
    {
        $request = Request::create('/api/'.str_repeat('a', 4000));
        $request->headers->set('User-Agent', str_repeat('b', 2000));
        $request->headers->set('X-Request-Id', str_repeat('c', 200));

        $payload = ApiRequestLogBuffer::normalizePayload($request, new Response, microtime(true));

        $this->assertSame(ApiRequestLogBuffer::MAX_PATH_LENGTH, mb_strlen($payload['path']));
        $this->assertSame(ApiRequestLogBuffer::MAX_USER_AGENT_LENGTH, mb_strlen((string) $payload['user_agent']));
        $this->assertSame(ApiRequestLogBuffer::MAX_REQUEST_ID_LENGTH, mb_strlen((string) $payload['request_id']));
    }

    public function test_it_falls_back_through_the_configured_request_id_headers(): void
    {
        $request = Request::create('/api/ping');
        $request->headers->set('X-Correlation-Id', 'corr-123');

        $payload = ApiRequestLogBuffer::normalizePayload($request, new Response, microtime(true));

        $this->assertSame('corr-123', $payload['request_id']);
    }

    public function test_non_uuid_correlation_ids_are_preserved(): void
    {
        $request = Request::create('/api/ping');
        $request->headers->set('X-Request-Id', '01JZ8YFB6M6ZQ4Q0V9F2P3T7XK');

        $payload = ApiRequestLogBuffer::normalizePayload($request, new Response, microtime(true));

        $this->assertSame('01JZ8YFB6M6ZQ4Q0V9F2P3T7XK', $payload['request_id']);
    }

    public function test_request_id_headers_can_be_reconfigured(): void
    {
        config()->set('api_telemetry.request_id_headers', ['X-Trace-Id']);

        $request = Request::create('/api/ping');
        $request->headers->set('X-Request-Id', 'ignored');
        $request->headers->set('X-Trace-Id', 'traced');

        $payload = ApiRequestLogBuffer::normalizePayload($request, new Response, microtime(true));

        $this->assertSame('traced', $payload['request_id']);
    }

    public function test_excluded_paths_are_not_recorded(): void
    {
        config()->set('api_telemetry.except', ['up', 'internal/*']);

        $this->assertFalse(ApiRequestLogBuffer::shouldRecord(Request::create('/up')));
        $this->assertFalse(ApiRequestLogBuffer::shouldRecord(Request::create('/internal/metrics')));
        $this->assertTrue(ApiRequestLogBuffer::shouldRecord(Request::create('/api/orders')));
    }

    public function test_recording_is_skipped_when_the_package_is_disabled(): void
    {
        config()->set('api_telemetry.enabled', false);

        $this->assertFalse(ApiRequestLogBuffer::shouldRecord(Request::create('/api/orders')));
    }

    public function test_a_zero_sampling_rate_records_nothing(): void
    {
        config()->set('api_telemetry.sampling_rate', 0.0);

        $this->assertFalse(ApiRequestLogBuffer::shouldRecord(Request::create('/api/orders')));
    }

    public function test_sampling_rates_outside_the_valid_range_are_clamped(): void
    {
        config()->set('api_telemetry.sampling_rate', 5.0);
        $this->assertTrue(ApiRequestLogBuffer::shouldRecord(Request::create('/api/orders')));

        config()->set('api_telemetry.sampling_rate', -2.0);
        $this->assertFalse(ApiRequestLogBuffer::shouldRecord(Request::create('/api/orders')));
    }

    public function test_prepare_for_insert_rejects_entries_missing_required_fields(): void
    {
        $this->assertNull(ApiRequestLogBuffer::prepareForInsert(['method' => 'GET']));
        $this->assertNull(ApiRequestLogBuffer::prepareForInsert([]));
    }

    public function test_prepare_for_insert_coerces_and_clamps_values(): void
    {
        $row = ApiRequestLogBuffer::prepareForInsert([
            'requested_at' => '2026-06-17 14:35:00',
            'method' => 'get',
            'path' => 'api/orders',
            'status_code' => '404',
            'duration_ms' => -17,
            'user_id' => '99',
        ]);

        $this->assertNotNull($row);
        $this->assertSame('/api/orders', $row['path']);
        $this->assertSame(404, $row['status_code']);
        $this->assertSame(0, $row['duration_ms']);
        $this->assertSame(99, $row['user_id']);
        $this->assertNull($row['route_name']);
    }

    public function test_string_user_ids_are_preserved_when_configured(): void
    {
        config()->set('api_telemetry.database.user_id_type', 'string');

        $row = ApiRequestLogBuffer::prepareForInsert([
            'requested_at' => '2026-06-17 14:35:00',
            'method' => 'GET',
            'path' => '/api/orders',
            'status_code' => 200,
            'duration_ms' => 5,
            'user_id' => '9d5f1a2e-0000-4000-8000-000000000000',
        ]);

        $this->assertNotNull($row);
        $this->assertSame('9d5f1a2e-0000-4000-8000-000000000000', $row['user_id']);
    }

    public function test_non_numeric_user_ids_become_null_for_integer_columns(): void
    {
        $row = ApiRequestLogBuffer::prepareForInsert([
            'requested_at' => '2026-06-17 14:35:00',
            'method' => 'GET',
            'path' => '/api/orders',
            'status_code' => 200,
            'duration_ms' => 5,
            'user_id' => '9d5f1a2e-0000-4000-8000-000000000000',
        ]);

        $this->assertNotNull($row);
        $this->assertNull($row['user_id']);
    }

    public function test_actor_keys_distinguish_guests_from_users(): void
    {
        $this->assertSame('guest', ApiRequestLogBuffer::actorKey(null));
        $this->assertSame('guest', ApiRequestLogBuffer::actorKey(''));
        $this->assertSame('user:42', ApiRequestLogBuffer::actorKey(42));
        $this->assertSame('user:abc', ApiRequestLogBuffer::actorKey('abc'));
    }

    public function test_actor_keys_fit_the_column_width(): void
    {
        $this->assertLessThanOrEqual(96, mb_strlen(ApiRequestLogBuffer::actorKey(str_repeat('x', 300))));
    }
}
