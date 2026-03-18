<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ElevenLabsService
{
    private const API_BASE = 'https://api.elevenlabs.io/v1';

    /**
     * Convert text to speech and return raw MP3 bytes.
     */
    public function textToSpeech(string $text, ?string $voiceId = null): ?string
    {
        $apiKey = config('services.elevenlabs.api_key');
        if (!$apiKey) {
            return null;
        }

        $voiceId = $voiceId ?? config('services.elevenlabs.voice_id');

        $response = Http::withHeaders([
            'xi-api-key' => $apiKey,
            'Content-Type' => 'application/json',
        ])->post(self::API_BASE . '/text-to-speech/' . $voiceId, [
            'text' => $text,
            'model_id' => 'eleven_multilingual_v2',
            'voice_settings' => [
                'stability' => 0.5,
                'similarity_boost' => 0.75,
                'style' => 0.3,
                'use_speaker_boost' => true,
            ],
        ]);

        if ($response->failed()) {
            Log::warning('ElevenLabs TTS failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        }

        return $response->body();
    }
}
