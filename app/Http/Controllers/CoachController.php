<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\WorkoutSession;
use App\Services\AICoachService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CoachController extends Controller
{
    public function __construct(
        private readonly AICoachService $coach,
    ) {}

    /**
     * Generate a live coaching cue with optional TTS audio.
     */
    public function apiCue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phase_type' => 'required|string',
            'phase_label' => 'required|string',
            'time_remaining_sec' => 'required|integer',
            'cadence_rpm' => 'required|integer',
            'target_rpm_low' => 'required|integer',
            'target_rpm_high' => 'required|integer',
            'heart_rate_bpm' => 'nullable|integer',
            'speed_kmh' => 'nullable|numeric',
            'distance_km' => 'nullable|numeric',
            'resistance_actual' => 'nullable|integer',
            'resistance_target' => 'nullable|integer',
            'trigger' => 'nullable|string|in:on_track,struggling,hr_high,phase_change,cadence_low,cadence_high',
            'context' => 'nullable|string',
        ]);

        $result = $this->coach->coachWithVoice($validated);

        return response()->json(['data' => $result, 'error' => null]);
    }

    /**
     * Analyze a completed session and return suggestions.
     */
    public function apiAnalyze(Request $request, int $sessionId): JsonResponse
    {
        $session = WorkoutSession::with('intervals', 'workout', 'telemetry')
            ->where('user_id', $request->user()->id)
            ->findOrFail($sessionId);

        // Compute avg resistance actual vs target per phase from telemetry
        $resistanceByPhase = $session->telemetry
            ->whereNotNull('resistance_actual')
            ->whereNotNull('resistance_target')
            ->groupBy('phase_type')
            ->map(fn ($rows) => [
                'resistance_actual_avg' => round($rows->avg('resistance_actual'), 1),
                'resistance_target' => round($rows->avg('resistance_target'), 1),
                'deviation' => round($rows->avg('resistance_actual') - $rows->avg('resistance_target'), 1),
                'sample_count' => $rows->count(),
            ]);

        $sessionData = [
            'workout_name' => $session->workout?->name,
            'intensity' => $session->intensity,
            'duration_planned_min' => $session->duration_planned_min,
            'duration_actual_sec' => $session->duration_actual_sec,
            'completed' => $session->completed,
            'perceived_effort' => $session->perceived_effort,
            'avg_cadence_rpm' => $session->avg_cadence_rpm,
            'avg_heart_rate_bpm' => $session->avg_heart_rate_bpm,
            'peak_heart_rate_bpm' => $session->peak_heart_rate_bpm,
            'resistance_by_phase' => $resistanceByPhase->toArray(),
            'intervals' => $session->intervals->map(fn ($i) => [
                'phase_type' => $i->phase_type,
                'target_rpm_low' => $i->target_rpm_low,
                'target_rpm_high' => $i->target_rpm_high,
                'target_resistance' => $i->target_resistance,
                'avg_cadence_rpm' => $i->avg_cadence_rpm,
                'avg_heart_rate_bpm' => $i->avg_heart_rate_bpm,
                'duration_sec' => $i->duration_sec,
                'actual_duration_sec' => $i->actual_duration_sec,
            ])->toArray(),
        ];

        $analysis = $this->coach->analyzeSession($sessionData);

        return response()->json([
            'data' => ['analysis' => $analysis],
            'error' => null,
        ]);
    }
}
