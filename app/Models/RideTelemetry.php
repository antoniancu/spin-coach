<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RideTelemetry extends Model
{
    public $timestamps = false;

    protected $table = 'ride_telemetry';

    protected $fillable = [
        'session_id',
        'elapsed_sec',
        'cadence_rpm',
        'heart_rate_bpm',
        'speed_kmh',
        'distance_km',
        'resistance_actual',
        'resistance_target',
        'phase_type',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'elapsed_sec' => 'integer',
            'cadence_rpm' => 'integer',
            'heart_rate_bpm' => 'integer',
            'speed_kmh' => 'float',
            'distance_km' => 'float',
            'resistance_actual' => 'integer',
            'resistance_target' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkoutSession::class, 'session_id');
    }
}
