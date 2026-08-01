<?php

namespace Systemverk\LaravelApiTelemetry\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Systemverk\LaravelApiTelemetry\Http\Middleware\LogApiRequest;
use Systemverk\LaravelApiTelemetry\Support\ApiRequestLogBuffer;
use Systemverk\LaravelApiTelemetry\Tests\Support\FakeRedisConnection;
use Systemverk\LaravelApiTelemetry\Tests\TestCase;

class LogApiRequestMiddlewareTest extends TestCase
{
    public function test_handle_returns_the_response_untouched_and_defers_the_write(): void
    {
        $redis = $this->fakeRedis();
        $middleware = new LogApiRequest;
        $request = Request::create('/api/orders');
        $response = new Response('ok', 200);

        $returned = $middleware->handle($request, fn () => $response);

        $this->assertSame($response, $returned);
        $this->assertSame([], $redis->store, 'handle() must not touch Redis.');
        $this->assertIsFloat($request->attributes->get(LogApiRequest::STARTED_AT));
    }

    public function test_terminate_buffers_the_request_and_sets_a_ttl(): void
    {
        $redis = $this->fakeRedis();

        $request = Request::create('/api/orders');
        $response = new Response('ok', 201);

        $this->runMiddleware($request, $response);

        $key = ApiRequestLogBuffer::currentMinuteKey();

        $this->assertArrayHasKey($key, $redis->store);
        $this->assertSame(7200, $redis->ttls[$key]);

        $entry = json_decode($redis->store[$key][0], true);

        $this->assertSame('/api/orders', $entry['path']);
        $this->assertSame(201, $entry['status_code']);
        $this->assertSame('GET', $entry['method']);
    }

    public function test_terminate_records_nothing_when_disabled(): void
    {
        config()->set('api_telemetry.enabled', false);
        $redis = $this->fakeRedis();

        $this->runMiddleware(Request::create('/api/orders'), new Response);

        $this->assertSame([], $redis->store);
    }

    public function test_terminate_respects_excluded_paths(): void
    {
        config()->set('api_telemetry.except', ['up']);
        $redis = $this->fakeRedis();

        $this->runMiddleware(Request::create('/up'), new Response);

        $this->assertSame([], $redis->store);
    }

    public function test_terminate_is_a_no_op_when_the_redis_connection_is_not_configured(): void
    {
        config()->set('api_telemetry.redis.connection', 'nonexistent');
        $redis = $this->fakeRedis();

        $this->runMiddleware(Request::create('/api/orders'), new Response);

        $this->assertSame([], $redis->store);
    }

    public function test_a_cluster_connection_is_recognised(): void
    {
        config()->set('api_telemetry.redis.connection', 'telemetry');
        config()->set('database.redis.clusters.telemetry', [['host' => '127.0.0.1', 'port' => 6379]]);

        $redis = $this->fakeRedis();

        $this->runMiddleware(Request::create('/api/orders'), new Response);

        $this->assertNotSame([], $redis->store);
    }

    public function test_a_redis_failure_is_logged_and_swallowed(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'Failed to buffer')
                && $context['error'] === 'connection refused');

        $redis = new FakeRedisConnection;
        $redis->failOn('rpush', new \RuntimeException('connection refused'));
        Redis::shouldReceive('connection')->andReturn($redis);

        $this->runMiddleware(Request::create('/api/orders'), new Response);
    }

    public function test_the_middleware_is_appended_to_the_api_group_by_default(): void
    {
        $this->assertContains(
            LogApiRequest::class,
            $this->app()->make(\Illuminate\Foundation\Http\Kernel::class)->getMiddlewareGroups()['api'] ?? []
        );
    }

    private function runMiddleware(Request $request, Response $response): void
    {
        $middleware = new LogApiRequest;
        $middleware->handle($request, fn () => $response);
        $middleware->terminate($request, $response);
    }
}
