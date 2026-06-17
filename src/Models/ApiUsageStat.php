<?php

namespace Systemverk\LaravelApiTelemetry\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $period_type
 * @property \Illuminate\Support\Carbon $period_start
 * @property int|null $user_id
 * @property string $actor_key
 * @property int $total_requests
 * @property int $responses_2xx
 * @property int $responses_4xx
 * @property int $responses_5xx
 */
class ApiUsageStat extends Model
{
    protected $guarded = [];

    protected $casts = [
        'period_start' => 'date',
        'actor_key' => 'string',
        'user_id' => 'integer',
        'total_requests' => 'integer',
        'responses_2xx' => 'integer',
        'responses_4xx' => 'integer',
        'responses_5xx' => 'integer',
    ];
}
