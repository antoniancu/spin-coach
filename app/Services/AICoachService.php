<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AICoachService
{
    private const API_BASE = 'https://api.anthropic.com/v1';

    public function __construct(
        private readonly ElevenLabsService $tts,
    ) {}

    /**
     * Generate a coaching prompt based on current ride state.
     * Returns short, punchy text suitable for TTS (1-2 sentences max).
     */
    public function generateCoachingCue(array $rideState): ?string
    {
        $apiKey = config('services.anthropic.api_key');
        if (!$apiKey) {
            return null;
        }

        $promptPath = resource_path('coach/system-prompt.md');
        $system = file_exists($promptPath)
            ? file_get_contents($promptPath)
            : 'You are a cycling coach. Give short, punchy cues under 25 words.';

        // Determine coaching trigger
        $trigger = $rideState['trigger'] ?? 'on_track';

        $resActual = $rideState['resistance_actual'] ?? 0;
        $resTarget = $rideState['resistance_target'] ?? 0;
        $resDiff = $resActual - $resTarget;
        $resNote = '';
        if ($resTarget > 0 && $resActual > 0) {
            if ($resDiff > 3) {
                $resNote = ' (ABOVE program by ' . $resDiff . ')';
            } elseif ($resDiff < -3) {
                $resNote = ' (BELOW program by ' . abs($resDiff) . ')';
            } else {
                $resNote = ' (matching program)';
            }
        }

        $userMsg = sprintf(
            "Coaching trigger: %s\nRide state:\n- Phase: %s (%s)\n- Time remaining: %ds\n- Cadence: %d RPM (target: %d-%d)\n- Heart rate: %s BPM\n- Speed: %s km/h\n- Distance: %s km\n- Resistance actual: %d/100%s\n- Resistance program target: %d/100\n%s",
            $trigger,
            $rideState['phase_type'] ?? 'unknown',
            $rideState['phase_label'] ?? '',
            $rideState['time_remaining_sec'] ?? 0,
            $rideState['cadence_rpm'] ?? 0,
            $rideState['target_rpm_low'] ?? 0,
            $rideState['target_rpm_high'] ?? 0,
            $rideState['heart_rate_bpm'] ?? 'n/a',
            $rideState['speed_kmh'] ?? 'n/a',
            $rideState['distance_km'] ?? '0',
            $resActual,
            $resNote,
            $resTarget,
            isset($rideState['context']) ? "\nContext: " . $rideState['context'] : '',
        );

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ])->timeout(10)->post(self::API_BASE . '/messages', [
            'model' => config('services.anthropic.model'),
            'max_tokens' => 80,
            'system' => $system,
            'messages' => [
                ['role' => 'user', 'content' => $userMsg],
            ],
        ]);

        if ($response->failed()) {
            Log::warning('AI Coach generation failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            return null;
        }

        $data = $response->json();
        return $data['content'][0]['text'] ?? null;
    }

    /**
     * Analyze a completed session and generate suggestions for next ride.
     */
    public function analyzeSession(array $sessionData): ?string
    {
        $apiKey = config('services.anthropic.api_key');
        if (!$apiKey) {
            return null;
        }

        $promptPath = resource_path('coach/analysis-prompt.md');
        $system = file_exists($promptPath)
            ? file_get_contents($promptPath)
            : 'You are a cycling coach. Give a brief post-ride summary in 3-4 sentences.';

        $userMsg = json_encode($sessionData, JSON_PRETTY_PRINT);

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ])->timeout(15)->post(self::API_BASE . '/messages', [
            'model' => config('services.anthropic.model'),
            'max_tokens' => 250,
            'system' => $system,
            'messages' => [
                ['role' => 'user', 'content' => $userMsg],
            ],
        ]);

        if ($response->failed()) {
            Log::warning('AI Coach analysis failed', ['status' => $response->status()]);
            return null;
        }

        $data = $response->json();
        return $data['content'][0]['text'] ?? null;
    }

    /**
     * Generate coaching text AND convert to speech audio (MP3 base64).
     */
    public function coachWithVoice(array $rideState): array
    {
        $text = $this->generateCoachingCue($rideState);
        if (!$text) {
            return ['text' => null, 'audio' => null];
        }

        $audioBytes = $this->tts->textToSpeech($text);
        $audioBase64 = $audioBytes ? base64_encode($audioBytes) : null;

        return ['text' => $text, 'audio' => $audioBase64];
    }
}
