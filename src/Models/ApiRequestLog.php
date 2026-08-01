<?php

namespace Systemverk\LaravelApiTelemetry\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Systemverk\LaravelApiTelemetry\Support\TelemetryConfig;

/**
 * A single recorded API request.
 *
 * All timestamps are stored in UTC regardless of the application timezone.
 *
 * @property int $id
 * @property \Illuminate\Support\Carbon $requested_at
 * @property string $method
 * @property string $path
 * @property string|null $route_name
 * @property int $status_code
 * @property int $duration_ms
 * @property int|string|null $user_id
 * @property string|null $ip_hash
 * @property string|null $user_agent
 * @property string|null $request_id
 */
class ApiRequestLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'requested_at' => 'datetime',
        'status_code' => 'integer',
        'duration_ms' => 'integer',
    ];

    public function getTable(): string
    {
        return TelemetryConfig::requestLogsTable();
    }

    public function getConnectionName(): ?string
    {
        return TelemetryConfig::databaseConnection() ?? parent::getConnectionName();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeBetween(Builder $query, mixed $from, mixed $to): Builder
    {
        return $query->whereBetween('requested_at', [$from, $to]);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeStatusClass(Builder $query, int $class): Builder
    {
        return $query->whereBetween('status_code', [$class * 100, $class * 100 + 99]);
    }
}
