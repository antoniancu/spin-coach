<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\VirtualRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RouteController extends Controller
{
    public function index(): View
    {
        return view('routes.index');
    }

    public function apiIndex(Request $request): JsonResponse
    {
        $query = VirtualRoute::where('active', true)->orderBy('sort_order');

        if ($request->has('difficulty')) {
            $query->where('difficulty', $request->input('difficulty'));
        }

        if ($request->has('location_type')) {
            $query->where('location_type', $request->input('location_type'));
        }

        $routes = $query->get()->map(fn (VirtualRoute $r) => [
            'id' => $r->id,
            'name' => $r->name,
            'location_type' => $r->location_type,
            'country' => $r->country,
            'difficulty' => $r->difficulty,
            'total_distance_km' => $r->total_distance_km,
            'elevation_gain_m' => $r->elevation_gain_m,
            'thumbnail_url' => $r->thumbnail_url,
            'description' => $r->description,
        ]);

        return response()->json(['data' => $routes, 'error' => null]);
    }

    public function apiWaypoints(int $id): JsonResponse
    {
        $route = VirtualRoute::where('active', true)->find($id);

        if (!$route) {
            return response()->json(['data' => null, 'error' => 'Route not found'], 404);
        }

        return response()->json([
            'data' => [
                'route_id' => $route->id,
                'name' => $route->name,
                'total_distance_km' => $route->total_distance_km,
                'waypoints' => $route->waypoints,
            ],
            'error' => null,
        ]);
    }
}
