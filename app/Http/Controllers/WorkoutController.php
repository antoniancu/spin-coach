<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\RideTelemetry;
use App\Models\Workout;
use App\Models\WorkoutSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkoutController extends Controller
{
    public function home(): View
    {
        return view('home');
    }

    public function ride(int $sessionId): View
    {
        $session = WorkoutSession::with('workout', 'virtualRoute')->findOrFail($sessionId);
        return view('ride', compact('session'));
    }

    public function apiIndex(Request $request): JsonResponse
    {
        $query = Workout::orderBy('sort_order');

        if ($request->has('intensity')) {
            $query->where('intensity', $request->input('intensity'));
        }

        if ($request->has('duration')) {
            $query->where('duration_min', (int) $request->input('duration'));
        }

        if ($request->has('intensity') && $request->has('duration')) {
            $workout = $query->first();
            if ($workout) {
                return response()->json([
                    'data' => [
                        'id' => $workout->id,
                        'name' => $workout->name,
                        'description' => $workout->description,
                        'duration_min' => $workout->duration_min,
                        'intensity' => $workout->intensity,
                        'phase_count' => count($workout->phases),
                        'phases' => $workout->phases,
                    ],
                    'error' => null,
                ]);
            }
            return response()->json(['data' => null, 'error' => 'No matching workout found'], 404);
        }

        $workouts = $query->get();
        $grouped = $workouts->groupBy('intensity');

        return response()->json(['data' => $grouped, 'error' => null]);
    }

    public function apiShow(int $id): JsonResponse
    {
        $workout = Workout::find($id);
        if (!$workout) {
            return response()->json(['data' => null, 'error' => 'Workout not found'], 404);
        }

        return response()->json(['data' => $workout, 'error' => null]);
    }

    public function apiStart(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'workout_id' => 'nullable|integer|exists:workouts,id',
            'intensity' => 'required|in:easy,medium,hard',
            'duration_planned_min' => 'required|integer|min:1',
            'virtual_route_id' => 'nullable|integer|exists:virtual_routes,id',
            'spotify_playlist_uri' => 'nullable|string',
        ]);

        $session = WorkoutSession::create([
            'user_id' => $request->user()->id,
            'workout_id' => $validated['workout_id'] ?? null,
            'virtual_route_id' => $validated['virtual_route_id'] ?? null,
            'intensity' => $validated['intensity'],
            'duration_planned_min' => $validated['duration_planned_min'],
            'started_at' => now(),
            'spotify_playlist_uri' => $validated['spotify_playlist_uri'] ?? null,
        ]);

        return response()->json([
            'data' => [
                'session_id' => $session->id,
                'started_at' => $session->started_at->toIso8601String(),
            ],
            'error' => null,
        ], 201);
    }

    public function apiInterval(Request $request, int $sessionId): JsonResponse
    {
        $session = WorkoutSession::where('user_id', $request->user()->id)->findOrFail($sessionId);

        $validated = $request->validate([
            'sequence' => 'required|integer|min:0',
            'phase_type' => 'required|in:warmup,work,rest,cooldown',
            'target_rpm_low' => 'required|integer',
            'target_rpm_high' => 'required|integer',
            'target_resistance' => 'required|integer|min:1|max:100',
            'duration_sec' => 'required|integer',
            'actual_duration_sec' => 'nullable|integer',
            'avg_cadence_rpm' => 'nullable|integer',
            'avg_heart_rate_bpm' => 'nullable|integer',
        ]);

        $interval = $session->intervals()->create(array_merge($validated, [
            'created_at' => now(),
        ]));

        return response()->json([
            'data' => ['interval_id' => $interval->id],
            'error' => null,
        ], 201);
    }

    public function apiFinish(Request $request, int $sessionId): JsonResponse
    {
        $session = WorkoutSession::where('user_id', $request->user()->id)->findOrFail($sessionId);

        $validated = $request->validate([
            'completed' => 'nullable|boolean',
            'duration_actual_sec' => 'nullable|integer',
            'avg_cadence_rpm' => 'nullable|integer',
            'avg_heart_rate_bpm' => 'nullable|integer',
            'peak_heart_rate_bpm' => 'nullable|integer',
            'calories_estimate' => 'nullable|integer',
            'distance_km_estimate' => 'nullable|numeric',
            'perceived_effort' => 'nullable|integer|min:1|max:5',
            'notes' => 'nullable|string',
            'laps_completed' => 'nullable|integer',
            'total_virtual_distance_km' => 'nullable|numeric',
        ]);

        $session->update(array_merge($validated, [
            'ended_at' => now(),
        ]));

        return response()->json([
            'data' => [
                'session_id' => $session->id,
                'summary_url' => '/history/' . $session->id,
            ],
            'error' => null,
        ]);
    }

    public function apiTelemetry(Request $request, int $sessionId): JsonResponse
    {
        $session = WorkoutSession::where('user_id', $request->user()->id)->findOrFail($sessionId);

        $validated = $request->validate([
            'points' => 'required|array|max:50',
            'points.*.elapsed_sec' => 'required|integer|min:0',
            'points.*.cadence_rpm' => 'nullable|integer|min:0|max:200',
            'points.*.heart_rate_bpm' => 'nullable|integer|min:0|max:250',
            'points.*.speed_kmh' => 'nullable|numeric|min:0|max:80',
            'points.*.distance_km' => 'nullable|numeric|min:0',
            'points.*.resistance_actual' => 'nullable|integer|min:0|max:100',
            'points.*.resistance_target' => 'nullable|integer|min:0|max:100',
            'points.*.phase_type' => 'nullable|string',
        ]);

        $rows = array_map(fn (array $pt) => [
            'session_id' => $session->id,
            'elapsed_sec' => $pt['elapsed_sec'],
            'cadence_rpm' => $pt['cadence_rpm'] ?? null,
            'heart_rate_bpm' => $pt['heart_rate_bpm'] ?? null,
            'speed_kmh' => $pt['speed_kmh'] ?? null,
            'distance_km' => $pt['distance_km'] ?? null,
            'resistance_actual' => $pt['resistance_actual'] ?? null,
            'resistance_target' => $pt['resistance_target'] ?? null,
            'phase_type' => $pt['phase_type'] ?? null,
            'recorded_at' => now(),
        ], $validated['points']);

        RideTelemetry::insert($rows);

        return response()->json(['data' => ['count' => count($rows)], 'error' => null]);
    }

    public function apiSessionShow(Request $request, int $sessionId): JsonResponse
    {
        $session = WorkoutSession::with('intervals', 'workout')
            ->where('user_id', $request->user()->id)
            ->findOrFail($sessionId);

        return response()->json([
            'data' => [
                'session' => $session,
                'intervals' => $session->intervals->sortBy('sequence')->values(),
                'workout' => $session->workout,
            ],
            'error' => null,
        ]);
    }
}
