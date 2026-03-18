<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workout_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('workout_id')->nullable()->constrained('workouts')->nullOnDelete();
            $table->foreignId('virtual_route_id')->nullable()->constrained('virtual_routes')->nullOnDelete();
            $table->enum('intensity', ['easy', 'medium', 'hard']);
            $table->integer('duration_planned_min');
            $table->integer('duration_actual_sec')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->boolean('completed')->default(false);
            $table->integer('avg_cadence_rpm')->nullable();
            $table->integer('avg_heart_rate_bpm')->nullable();
            $table->integer('peak_heart_rate_bpm')->nullable();
            $table->integer('calories_estimate')->nullable();
            $table->float('distance_km_estimate')->nullable();
            $table->string('spotify_playlist_uri')->nullable();
            $table->text('notes')->nullable();
            $table->integer('laps_completed')->nullable()->default(0);
            $table->float('total_virtual_distance_km')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'started_at']);
            $table->index('completed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workout_sessions');
    }
};
