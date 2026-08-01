<?php

namespace Systemverk\LaravelApiTelemetry\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Systemverk\LaravelApiTelemetry\Support\TelemetryConfig;

/**
 * An aggregated usage row for one actor within one period.
 *
 * @property int $id
 * @property string $period_type
 * @property \Illuminate\Support\Carbon $period_start
 * @property int|string|null $user_id
 * @property string $actor_key
 * @property int $total_requests
 * @property int $responses_1xx
 * @property int $responses_2xx
 * @property int $responses_3xx
 * @property int $responses_4xx
 * @property int $responses_5xx
 */
class ApiUsageStat extends Model
{
    protected $guarded = [];

    protected $casts = [
        'period_start' => 'date',
        'actor_key' => 'string',
        'total_requests' => 'integer',
        'responses_1xx' => 'integer',
        'responses_2xx' => 'integer',
        'responses_3xx' => 'integer',
        'responses_4xx' => 'integer',
        'responses_5xx' => 'integer',
    ];

    public function getTable(): string
    {
        return TelemetryConfig::usageStatsTable();
    }

    public function getConnectionName(): ?string
    {
        return TelemetryConfig::databaseConnection() ?? parent::getConnectionName();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeDaily(Builder $query): Builder
    {
        return $query->where('period_type', 'day');
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeMonthly(Builder $query): Builder
    {
        return $query->where('period_type', 'month');
    }
}
