<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VirtualRoute extends Model
{
    protected $fillable = [
        'name',
        'description',
        'location_type',
        'country',
        'region',
        'difficulty',
        'total_distance_km',
        'elevation_gain_m',
        'waypoints',
        'thumbnail_url',
        'active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'waypoints' => 'array',
            'total_distance_km' => 'float',
            'elevation_gain_m' => 'integer',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(WorkoutSession::class);
    }
}
