<?php

namespace Systemverk\LaravelApiTelemetry\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Systemverk\LaravelApiTelemetry\Support\ApiRequestLogBuffer;

class LogApiRequest
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        $response = $next($request);

        if (! config('api_request_logging.enabled', true)) {
            return $response;
        }

        $redisConnection = ApiRequestLogBuffer::redisConnection();

        if ($redisConnection === '') {
            return $response;
        }

        if (config("database.redis.{$redisConnection}") === null) {
            return $response;
        }

        try {
            $payload = ApiRequestLogBuffer::normalizePayload($request, $response, $startedAt);
            $serializedPayload = json_encode($payload, JSON_THROW_ON_ERROR);
            $key = ApiRequestLogBuffer::currentMinuteKey();

            $redis = Redis::connection($redisConnection);
            $redis->rpush($key, $serializedPayload);
            $redis->expire($key, ApiRequestLogBuffer::redisTtlSeconds());
        } catch (\Throwable $exception) {
            try {
                Log::warning('Failed to buffer API request log entry.', [
                    'error' => $exception->getMessage(),
                ]);
            } catch (\Throwable) {
                //
            }
        }

        return $response;
    }
}
