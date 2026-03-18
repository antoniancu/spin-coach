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
        // Note: rename already applied in partial run
        if (!Schema::hasColumn('ride_telemetry', 'resistance_actual')) {
            Schema::table('ride_telemetry', function (Blueprint $table) {
                $table->renameColumn('resistance', 'resistance_actual');
            });
        }
        if (!Schema::hasColumn('ride_telemetry', 'resistance_target')) {
            Schema::table('ride_telemetry', function (Blueprint $table) {
                $table->unsignedSmallInteger('resistance_target')->nullable()->after('resistance_actual');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ride_telemetry', function (Blueprint $table) {
            $table->dropColumn('resistance_target');
            $table->renameColumn('resistance_actual', 'resistance');
        });
    }
};
