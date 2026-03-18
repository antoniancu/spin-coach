<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ride_telemetry', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('workout_sessions')->cascadeOnDelete();
            $table->unsignedInteger('elapsed_sec');
            $table->unsignedSmallInteger('cadence_rpm')->nullable();
            $table->unsignedSmallInteger('heart_rate_bpm')->nullable();
            $table->decimal('speed_kmh', 5, 1)->nullable();
            $table->decimal('distance_km', 6, 3)->nullable();
            $table->unsignedSmallInteger('resistance')->nullable();
            $table->string('phase_type', 20)->nullable();
            $table->timestamp('recorded_at');
            $table->index(['session_id', 'elapsed_sec']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ride_telemetry');
    }
};
