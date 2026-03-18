<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Workout;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class WorkoutSeeder extends Seeder
{
    public function run(): void
    {
        Schema::disableForeignKeyConstraints();
        Workout::truncate();
        Schema::enableForeignKeyConstraints();

        $json = file_get_contents(public_path('data/workouts.json'));
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        foreach ($data['workouts'] as $workout) {
            foreach ($workout['variants'] as $variant) {
                Workout::create([
                    'name' => $workout['name'],
                    'intensity' => $workout['intensity'],
                    'description' => $workout['description'],
                    'duration_min' => $variant['duration_min'],
                    'phases' => $variant['phases'],
                    'sort_order' => $variant['sort_order'],
                ]);
            }
        }
    }
}
