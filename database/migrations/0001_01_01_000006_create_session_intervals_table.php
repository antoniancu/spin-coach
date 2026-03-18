<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_intervals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('workout_sessions')->cascadeOnDelete();
            $table->integer('sequence');
            $table->enum('phase_type', ['warmup', 'work', 'rest', 'cooldown']);
            $table->integer('target_rpm_low');
            $table->integer('target_rpm_high');
            $table->integer('target_resistance');
            $table->integer('duration_sec');
            $table->integer('actual_duration_sec')->nullable();
            $table->integer('avg_cadence_rpm')->nullable();
            $table->integer('avg_heart_rate_bpm')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['session_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_intervals');
    }
};
