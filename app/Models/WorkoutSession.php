<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkoutSession extends Model
{
    protected $fillable = [
        'user_id',
        'workout_id',
        'virtual_route_id',
        'intensity',
        'duration_planned_min',
        'duration_actual_sec',
        'started_at',
        'ended_at',
        'completed',
        'avg_cadence_rpm',
        'avg_heart_rate_bpm',
        'peak_heart_rate_bpm',
        'calories_estimate',
        'distance_km_estimate',
        'spotify_playlist_uri',
        'perceived_effort',
        'notes',
        'laps_completed',
        'total_virtual_distance_km',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'completed' => 'boolean',
            'duration_planned_min' => 'integer',
            'duration_actual_sec' => 'integer',
            'avg_cadence_rpm' => 'integer',
            'avg_heart_rate_bpm' => 'integer',
            'peak_heart_rate_bpm' => 'integer',
            'perceived_effort' => 'integer',
            'calories_estimate' => 'integer',
            'distance_km_estimate' => 'float',
            'laps_completed' => 'integer',
            'total_virtual_distance_km' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workout(): BelongsTo
    {
        return $this->belongsTo(Workout::class);
    }

    public function virtualRoute(): BelongsTo
    {
        return $this->belongsTo(VirtualRoute::class);
    }

    public function intervals(): HasMany
    {
        return $this->hasMany(SessionInterval::class, 'session_id');
    }

    public function telemetry(): HasMany
    {
        return $this->hasMany(RideTelemetry::class, 'session_id');
    }
}
