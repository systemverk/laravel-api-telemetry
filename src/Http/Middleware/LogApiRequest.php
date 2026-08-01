<?php

namespace Systemverk\LaravelApiTelemetry\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Systemverk\LaravelApiTelemetry\Support\ApiRequestLogBuffer;
use Systemverk\LaravelApiTelemetry\Support\TelemetryConfig;

class LogApiRequest
{
    /**
     * Attribute used to carry the start timestamp from handle() to terminate().
     */
    public const STARTED_AT = 'api_telemetry.started_at';

    /**
     * Handle an incoming request.
     *
     * The request is only timestamped here; the actual buffering happens in
     * terminate() so that the two Redis round trips do not sit on the critical
     * path of the response.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::STARTED_AT, microtime(true));

        return $next($request);
    }

    /**
     * Buffer the request after the response has been sent to the client.
     */
    public function terminate(Request $request, Response $response): void
    {
        if (! ApiRequestLogBuffer::shouldRecord($request)) {
            return;
        }

        try {
            $connection = TelemetryConfig::redisConnection();

            if (! $this->redisConnectionIsConfigured($connection)) {
                return;
            }

            $startedAt = $request->attributes->get(self::STARTED_AT);
            $startedAt = is_float($startedAt) ? $startedAt : microtime(true);

            $payload = ApiRequestLogBuffer::normalizePayload($request, $response, $startedAt);
            $serialized = json_encode($payload, JSON_THROW_ON_ERROR);
            $key = ApiRequestLogBuffer::currentMinuteKey();

            $redis = Redis::connection($connection);
            $redis->rpush($key, $serialized);
            $redis->expire($key, TelemetryConfig::redisTtlSeconds());
        } catch (\Throwable $exception) {
            $this->reportSilently($exception);
        }
    }

    /**
     * Telemetry must never take an application down, so a missing or misnamed
     * Redis connection is treated as "disabled" rather than as an error.
     */
    private function redisConnectionIsConfigured(string $connection): bool
    {
        return config("database.redis.{$connection}") !== null
            || config("database.redis.clusters.{$connection}") !== null;
    }

    private function reportSilently(\Throwable $exception): void
    {
        try {
            Log::warning('Failed to buffer API request log entry.', [
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);
        } catch (\Throwable) {
            //
        }
    }
}
