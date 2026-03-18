<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workout extends Model
{
    protected $fillable = [
        'name',
        'intensity',
        'duration_min',
        'description',
        'phases',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'phases' => 'array',
            'duration_min' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(WorkoutSession::class);
    }
}
