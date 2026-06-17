<?php

namespace Systemverk\LaravelApiTelemetry\Support;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ApiRequestLogBuffer
{
    public static function currentMinuteKey(): string
    {
        return self::keyForMinute(now()->utc());
    }

    public static function keyForMinute(CarbonInterface $minute): string
    {
        return self::keyPrefix().$minute->format('YmdHi');
    }

    public static function keyPrefix(): string
    {
        return config('api_request_logging.redis_key_prefix', 'api_request_logs:');
    }

    public static function redisConnection(): string
    {
        return config('api_request_logging.redis_connection', 'default');
    }

    public static function redisTtlSeconds(): int
    {
        return (int) config('api_request_logging.redis_ttl_seconds', 7200);
    }

    /**
     * @return array{
     *   requested_at: string,
     *   method: string,
     *   path: string,
     *   route_name: string|null,
     *   status_code: int,
     *   duration_ms: int,
     *   user_id: int|null,
     *   ip_hash: string|null,
     *   user_agent: string|null,
     *   request_id: string|null
     * }
     */
    public static function normalizePayload(Request $request, SymfonyResponse $response, float $startedAt): array
    {
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        return [
            'requested_at' => now()->utc()->toDateTimeString(),
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'route_name' => $request->route()?->getName(),
            'status_code' => $response->getStatusCode(),
            'duration_ms' => $durationMs,
            'user_id' => $request->user()?->id,
            'ip_hash' => self::hashIpAddress($request->ip()),
            'user_agent' => self::normalizeUserAgent($request->userAgent()),
            'request_id' => self::resolveRequestId($request, $response),
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>|null
     */
    public static function prepareForInsert(array $entry): ?array
    {
        if (! isset($entry['requested_at'], $entry['method'], $entry['path'], $entry['status_code'], $entry['duration_ms'])) {
            return null;
        }

        return [
            'requested_at' => $entry['requested_at'],
            'method' => $entry['method'],
            'path' => $entry['path'],
            'route_name' => $entry['route_name'] ?? null,
            'status_code' => (int) $entry['status_code'],
            'duration_ms' => max(0, (int) $entry['duration_ms']),
            'user_id' => isset($entry['user_id']) ? (int) $entry['user_id'] : null,
            'ip_hash' => $entry['ip_hash'] ?? null,
            'user_agent' => self::normalizeUserAgent($entry['user_agent'] ?? null),
            'request_id' => $entry['request_id'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private static function hashIpAddress(?string $ipAddress): ?string
    {
        if ($ipAddress === null || $ipAddress === '') {
            return null;
        }

        return hash('sha256', config('app.key').$ipAddress);
    }

    private static function normalizeUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        return mb_substr($userAgent, 0, 512);
    }

    private static function resolveRequestId(Request $request, SymfonyResponse $response): ?string
    {
        $candidate = $request->header('X-Request-Id')
            ?? $request->header('X-Correlation-Id')
            ?? $response->headers->get('X-Request-Id');

        if (! is_string($candidate)) {
            return null;
        }

        $candidate = trim($candidate);

        if ($candidate === '' || ! Str::isUuid($candidate)) {
            return null;
        }

        return $candidate;
    }
}
