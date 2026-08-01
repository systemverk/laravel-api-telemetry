<?php

namespace Systemverk\LaravelApiTelemetry\Support;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ApiRequestLogBuffer
{
    public const MAX_PATH_LENGTH = 1024;

    public const MAX_USER_AGENT_LENGTH = 512;

    public const MAX_REQUEST_ID_LENGTH = 64;

    /**
     * Redis key holding the per-minute buffer the middleware appends to.
     */
    public static function currentMinuteKey(): string
    {
        return self::keyForMinute(Carbon::now('UTC'));
    }

    public static function keyForMinute(CarbonInterface $minute): string
    {
        return TelemetryConfig::redisKeyPrefix().'requests:'.$minute->format('YmdHi');
    }

    /**
     * Redis set tracking buffers that have been claimed for flushing but not yet
     * confirmed written to the database, so a crashed flush can be retried.
     */
    public static function processingRegistryKey(): string
    {
        return TelemetryConfig::redisKeyPrefix().'processing';
    }

    public static function lockKeyFor(string $processingKey): string
    {
        return $processingKey.':lock';
    }

    /**
     * Decide whether a request is eligible for recording.
     *
     * Exclusion is checked before sampling so that excluded paths never consume
     * randomness, which keeps the sampling tests deterministic.
     */
    public static function shouldRecord(Request $request): bool
    {
        if (! TelemetryConfig::enabled()) {
            return false;
        }

        $except = TelemetryConfig::exceptPaths();

        if ($except !== [] && $request->is(...$except)) {
            return false;
        }

        $rate = TelemetryConfig::samplingRate();

        if ($rate >= 1.0) {
            return true;
        }

        if ($rate <= 0.0) {
            return false;
        }

        return (random_int(1, 1_000_000) / 1_000_000) <= $rate;
    }

    /**
     * @return array{
     *   requested_at: string,
     *   method: string,
     *   path: string,
     *   route_name: string|null,
     *   status_code: int,
     *   duration_ms: int,
     *   user_id: int|string|null,
     *   ip_hash: string|null,
     *   user_agent: string|null,
     *   request_id: string|null
     * }
     */
    public static function normalizePayload(Request $request, SymfonyResponse $response, float $startedAt): array
    {
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        return [
            'requested_at' => Carbon::now('UTC')->toDateTimeString(),
            'method' => mb_substr($request->method(), 0, 16),
            'path' => self::normalizePath($request->path()),
            'route_name' => $request->route()?->getName(),
            'status_code' => $response->getStatusCode(),
            'duration_ms' => max(0, $durationMs),
            'user_id' => self::resolveUserId($request),
            'ip_hash' => self::hashIpAddress($request->ip()),
            'user_agent' => TelemetryConfig::recordUserAgent()
                ? self::truncate($request->userAgent(), self::MAX_USER_AGENT_LENGTH)
                : null,
            'request_id' => self::resolveRequestId($request, $response),
        ];
    }

    /**
     * Turn a decoded buffer entry into a row ready for a bulk insert.
     *
     * Returns null when the entry is missing required fields, which can happen
     * if a buffer written by an older version of the package is still draining.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>|null
     */
    public static function prepareForInsert(array $entry): ?array
    {
        if (! isset($entry['requested_at'], $entry['method'], $entry['path'], $entry['status_code'], $entry['duration_ms'])) {
            return null;
        }

        if (! is_scalar($entry['requested_at']) || ! is_scalar($entry['method']) || ! is_scalar($entry['path'])) {
            return null;
        }

        $now = Carbon::now('UTC');

        return [
            'requested_at' => (string) $entry['requested_at'],
            'method' => mb_substr((string) $entry['method'], 0, 16),
            'path' => self::normalizePath((string) $entry['path']),
            'route_name' => self::truncate(self::stringOrNull($entry['route_name'] ?? null), 255),
            'status_code' => (int) $entry['status_code'],
            'duration_ms' => max(0, (int) $entry['duration_ms']),
            'user_id' => self::castUserId($entry['user_id'] ?? null),
            'ip_hash' => self::truncate(self::stringOrNull($entry['ip_hash'] ?? null), 64),
            'user_agent' => self::truncate(self::stringOrNull($entry['user_agent'] ?? null), self::MAX_USER_AGENT_LENGTH),
            'request_id' => self::truncate(self::stringOrNull($entry['request_id'] ?? null), self::MAX_REQUEST_ID_LENGTH),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Bucket key identifying the actor a request is attributed to.
     */
    public static function actorKey(int|string|null $userId): string
    {
        if ($userId === null || $userId === '') {
            return 'guest';
        }

        return mb_substr('user:'.$userId, 0, 96);
    }

    private static function normalizePath(string $path): string
    {
        return mb_substr('/'.ltrim($path, '/'), 0, self::MAX_PATH_LENGTH);
    }

    private static function resolveUserId(Request $request): int|string|null
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        $identifier = method_exists($user, 'getAuthIdentifier')
            ? $user->getAuthIdentifier()
            : null;

        return self::castUserId($identifier);
    }

    private static function castUserId(mixed $identifier): int|string|null
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }

        if (! is_scalar($identifier)) {
            return null;
        }

        if (TelemetryConfig::userIdType() === 'string') {
            return mb_substr((string) $identifier, 0, 64);
        }

        return is_numeric($identifier) ? (int) $identifier : null;
    }

    private static function hashIpAddress(?string $ipAddress): ?string
    {
        if (! TelemetryConfig::hashIps() || $ipAddress === null || $ipAddress === '') {
            return null;
        }

        return hash('sha256', TelemetryConfig::ipHashSalt().$ipAddress);
    }

    private static function resolveRequestId(Request $request, SymfonyResponse $response): ?string
    {
        foreach (TelemetryConfig::requestIdHeaders() as $header) {
            $candidate = $request->headers->get($header) ?? $response->headers->get($header);

            if (! is_string($candidate)) {
                continue;
            }

            $candidate = trim($candidate);

            if ($candidate !== '') {
                return mb_substr($candidate, 0, self::MAX_REQUEST_ID_LENGTH);
            }
        }

        return null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private static function truncate(?string $value, int $length): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $length);
    }
}
