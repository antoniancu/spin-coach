<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('virtual_routes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('location_type', ['city', 'mountain', 'forest', 'coastal', 'boardwalk']);
            $table->string('country');
            $table->string('region')->nullable();
            $table->enum('difficulty', ['flat', 'rolling', 'hilly']);
            $table->float('total_distance_km');
            $table->integer('elevation_gain_m')->default(0);
            $table->json('waypoints');
            $table->string('thumbnail_url')->nullable();
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('virtual_routes');
    }
};
