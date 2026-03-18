<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\VirtualRoute;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class RouteSeeder extends Seeder
{
    public function run(): void
    {
        Schema::disableForeignKeyConstraints();
        VirtualRoute::truncate();
        Schema::enableForeignKeyConstraints();

        $json = file_get_contents(public_path('data/routes.json'));
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        foreach ($data['routes'] as $route) {
            VirtualRoute::create([
                'name' => $route['name'],
                'description' => $route['description'],
                'location_type' => $route['location_type'],
                'country' => $route['country'],
                'region' => $route['region'] ?? null,
                'difficulty' => $route['difficulty'],
                'total_distance_km' => $route['total_distance_km'],
                'elevation_gain_m' => $route['elevation_gain_m'],
                'waypoints' => $route['waypoints'],
                'thumbnail_url' => $route['thumbnail_url'] ?? null,
                'active' => $route['active'] ?? true,
                'sort_order' => $route['sort_order'] ?? 0,
            ]);
        }
    }
}
