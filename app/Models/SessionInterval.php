<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SessionInterval extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'session_id',
        'sequence',
        'phase_type',
        'target_rpm_low',
        'target_rpm_high',
        'target_resistance',
        'duration_sec',
        'actual_duration_sec',
        'avg_cadence_rpm',
        'avg_heart_rate_bpm',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'target_rpm_low' => 'integer',
            'target_rpm_high' => 'integer',
            'target_resistance' => 'integer',
            'duration_sec' => 'integer',
            'actual_duration_sec' => 'integer',
            'avg_cadence_rpm' => 'integer',
            'avg_heart_rate_bpm' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkoutSession::class, 'session_id');
    }
}
