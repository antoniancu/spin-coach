<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\WorkoutSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        return view('history.index');
    }

    public function show(int $id): View
    {
        $session = WorkoutSession::with('workout', 'intervals', 'virtualRoute')->findOrFail($id);
        return view('history.show', compact('session'));
    }

    public function apiHistory(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 20);
        $page = (int) $request->input('page', 1);

        $query = WorkoutSession::with('workout')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('started_at');

        $total = $query->count();
        $sessions = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        $data = $sessions->map(fn (WorkoutSession $s) => [
            'id' => $s->id,
            'workout_name' => $s->workout?->name,
            'intensity' => $s->intensity,
            'started_at' => $s->started_at?->toIso8601String(),
            'duration_actual_sec' => $s->duration_actual_sec,
            'completed' => $s->completed,
            'avg_cadence_rpm' => $s->avg_cadence_rpm,
            'calories_estimate' => $s->calories_estimate,
        ]);

        return response()->json([
            'data' => [
                'sessions' => $data,
                'total' => $total,
                'page' => $page,
            ],
            'error' => null,
        ]);
    }

    public function apiUpdateNotes(Request $request, int $sessionId): JsonResponse
    {
        $session = WorkoutSession::where('user_id', $request->user()->id)->findOrFail($sessionId);

        $validated = $request->validate([
            'notes' => 'nullable|string',
        ]);

        $session->update(['notes' => $validated['notes']]);

        return response()->json([
            'data' => ['session_id' => $session->id],
            'error' => null,
        ]);
    }
}
