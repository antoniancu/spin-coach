<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ElevenLabsService
{
    private const API_BASE = 'https://api.elevenlabs.io/v1';
    private const CACHE_DIR = 'tts-cache';

    /**
     * Convert text to speech. Returns raw MP3 bytes.
     * Caches to disk so each unique text+voice is only generated once.
     */
    public function textToSpeech(string $text, ?string $voiceId = null): ?string
    {
        $apiKey = config('services.elevenlabs.api_key');
        if (!$apiKey) {
            return null;
        }

        $voiceId = $voiceId ?? config('services.elevenlabs.voice_id');
        $cacheKey = self::CACHE_DIR . '/' . md5($voiceId . '|' . $text) . '.mp3';

        // Check disk cache first
        if (Storage::disk('local')->exists($cacheKey)) {
            return Storage::disk('local')->get($cacheKey);
        }

        // Generate via API
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
                'body' => substr($response->body(), 0, 500),
                'voice_id' => $voiceId,
            ]);
            return null;
        }

        $audio = $response->body();

        // Save to disk cache
        Storage::disk('local')->put($cacheKey, $audio);
        Log::info('TTS cached', ['key' => $cacheKey, 'bytes' => strlen($audio)]);

        return $audio;
    }

    /**
     * Get the number of cached TTS files.
     */
    public function getCacheStats(): array
    {
        $files = Storage::disk('local')->files(self::CACHE_DIR);
        $totalBytes = 0;
        foreach ($files as $f) {
            $totalBytes += Storage::disk('local')->size($f);
        }

        return [
            'count' => count($files),
            'size_mb' => round($totalBytes / 1024 / 1024, 2),
        ];
    }
}
