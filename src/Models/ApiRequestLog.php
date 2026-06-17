<?php

namespace Systemverk\LaravelApiTelemetry\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property \Illuminate\Support\Carbon $requested_at
 * @property int $status_code
 * @property int $duration_ms
 * @property int|null $user_id
 */
class ApiRequestLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'requested_at' => 'datetime',
        'status_code' => 'integer',
        'duration_ms' => 'integer',
        'user_id' => 'integer',
    ];
}
