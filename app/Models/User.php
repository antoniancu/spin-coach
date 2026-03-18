<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'name',
        'avatar_emoji',
        'color_hex',
    ];

    public function workoutSessions(): HasMany
    {
        return $this->hasMany(WorkoutSession::class);
    }
}
