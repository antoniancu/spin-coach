<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SpotifyToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SpotifyService
{
    private const API_BASE = 'https://api.spotify.com/v1';
    private const TOKEN_URL = 'https://accounts.spotify.com/api/token';

    public function isConnected(): bool
    {
        return SpotifyToken::find(1) !== null;
    }

    public function getExpiresAt(): ?string
    {
        $token = SpotifyToken::find(1);
        return $token?->expires_at?->toIso8601String();
    }

    public function getDevices(): array
    {
        $response = $this->request('GET', '/me/player/devices');
        return $response['devices'] ?? [];
    }

    public function getNowPlaying(): ?array
    {
        $response = $this->request('GET', '/me/player/currently-playing');

        if (empty($response) || !isset($response['item'])) {
            return null;
        }

        $item = $response['item'];
        return [
            'name' => $item['name'] ?? '',
            'artist' => implode(', ', array_map(fn ($a) => $a['name'], $item['artists'] ?? [])),
            'album_art_url' => $item['album']['images'][0]['url'] ?? null,
            'duration_ms' => $item['duration_ms'] ?? 0,
            'progress_ms' => $response['progress_ms'] ?? 0,
        ];
    }

    public function play(?string $deviceId = null, ?string $contextUri = null, int $volume = 80): void
    {
        $query = $deviceId ? '?device_id=' . urlencode($deviceId) : '';
        $body = [];

        if ($contextUri) {
            $body['context_uri'] = $contextUri;
        }

        $this->request('PUT', '/me/player/play' . $query, $body);

        if ($volume !== 80) {
            $this->setVolume($volume, $deviceId);
        }
    }

    public function pause(?string $deviceId = null): void
    {
        $query = $deviceId ? '?device_id=' . urlencode($deviceId) : '';
        $this->request('PUT', '/me/player/pause' . $query);
    }

    public function next(?string $deviceId = null): void
    {
        $query = $deviceId ? '?device_id=' . urlencode($deviceId) : '';
        $this->request('POST', '/me/player/next' . $query);
    }

    public function setVolume(int $percent, ?string $deviceId = null): void
    {
        $percent = max(0, min(100, $percent));
        $query = '?volume_percent=' . $percent;
        if ($deviceId) {
            $query .= '&device_id=' . urlencode($deviceId);
        }
        $this->request('PUT', '/me/player/volume' . $query);
    }

    /**
     * Get full now-playing state including track URI and progress.
     */
    public function getNowPlayingFull(): ?array
    {
        $response = $this->request('GET', '/me/player/currently-playing');

        if (empty($response) || !isset($response['item'])) {
            return null;
        }

        $item = $response['item'];
        return [
            'uri' => $item['uri'] ?? '',
            'id' => $item['id'] ?? '',
            'name' => $item['name'] ?? '',
            'artist' => implode(', ', array_map(fn ($a) => $a['name'], $item['artists'] ?? [])),
            'duration_ms' => $item['duration_ms'] ?? 0,
            'progress_ms' => $response['progress_ms'] ?? 0,
            'is_playing' => $response['is_playing'] ?? false,
        ];
    }

    /**
     * Get audio features (tempo/energy/etc) for up to 100 track IDs.
     *
     * @param  array<string>  $trackIds
     * @return array<string, array{tempo: float, energy: float, danceability: float}>
     */
    public function getAudioFeatures(array $trackIds): array
    {
        if (empty($trackIds)) {
            return [];
        }

        $chunks = array_chunk($trackIds, 100);
        $result = [];

        foreach ($chunks as $chunk) {
            $response = $this->request('GET', '/audio-features?ids=' . implode(',', $chunk));
            foreach ($response['audio_features'] ?? [] as $af) {
                if ($af && isset($af['id'])) {
                    $result[$af['id']] = [
                        'tempo' => (float) ($af['tempo'] ?? 0),
                        'energy' => (float) ($af['energy'] ?? 0),
                        'danceability' => (float) ($af['danceability'] ?? 0),
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Get recommendations from Spotify with target tempo.
     *
     * @param  array<string>  $seedTrackIds  Up to 5 seed track IDs
     * @param  array<string>  $seedGenres    Up to 5 genre seeds
     */
    public function getRecommendations(
        float $targetTempo,
        array $seedTrackIds = [],
        array $seedGenres = [],
        float $minEnergy = 0.4,
        int $limit = 20,
    ): array {
        $params = [
            'limit' => $limit,
            'target_tempo' => $targetTempo,
            'min_tempo' => max(60, $targetTempo - 15),
            'max_tempo' => min(200, $targetTempo + 15),
            'min_energy' => $minEnergy,
        ];

        if (!empty($seedTrackIds)) {
            $params['seed_tracks'] = implode(',', array_slice($seedTrackIds, 0, 5));
        }
        if (!empty($seedGenres)) {
            $params['seed_genres'] = implode(',', array_slice($seedGenres, 0, 5));
        }

        // Must have at least one seed
        if (empty($seedTrackIds) && empty($seedGenres)) {
            $params['seed_genres'] = 'work-out,electronic,pop';
        }

        $query = '?' . http_build_query($params);
        $response = $this->request('GET', '/recommendations' . $query);

        return array_map(fn ($t) => [
            'uri' => $t['uri'],
            'id' => $t['id'],
            'name' => $t['name'],
            'artist' => implode(', ', array_map(fn ($a) => $a['name'], $t['artists'] ?? [])),
            'duration_ms' => $t['duration_ms'] ?? 0,
        ], $response['tracks'] ?? []);
    }

    /**
     * Add a track to the user's playback queue.
     */
    public function queueTrack(string $trackUri, ?string $deviceId = null): void
    {
        $query = '?uri=' . urlencode($trackUri);
        if ($deviceId) {
            $query .= '&device_id=' . urlencode($deviceId);
        }
        $this->request('POST', '/me/player/queue' . $query);
    }

    public function exchangeCode(string $code): SpotifyToken
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('services.spotify.redirect_uri'),
            'client_id' => config('services.spotify.client_id'),
            'client_secret' => config('services.spotify.client_secret'),
        ]);

        $data = $response->json();

        return SpotifyToken::updateOrCreate(['id' => 1], [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'expires_at' => now()->addSeconds((int) $data['expires_in']),
            'scope' => $data['scope'] ?? '',
        ]);
    }

    private function ensureFreshToken(): string
    {
        $token = SpotifyToken::find(1);

        if (!$token) {
            throw new \RuntimeException('Spotify not connected');
        }

        if ($token->expires_at->subSeconds(60)->isPast()) {
            $response = Http::asForm()->post(self::TOKEN_URL, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $token->refresh_token,
                'client_id' => config('services.spotify.client_id'),
                'client_secret' => config('services.spotify.client_secret'),
            ]);

            if ($response->failed()) {
                Log::error('Spotify token refresh failed', ['status' => $response->status()]);
                throw new \RuntimeException('Spotify token refresh failed');
            }

            $data = $response->json();

            $token->update([
                'access_token' => $data['access_token'],
                'expires_at' => now()->addSeconds((int) $data['expires_in']),
                'refresh_token' => $data['refresh_token'] ?? $token->refresh_token,
            ]);
        }

        return $token->access_token;
    }

    private function request(string $method, string $endpoint, array $body = []): array
    {
        $accessToken = $this->ensureFreshToken();

        $request = Http::withToken($accessToken)->acceptJson();

        $url = self::API_BASE . $endpoint;

        $response = match (strtoupper($method)) {
            'GET' => $request->get($url),
            'POST' => $request->post($url, $body),
            'PUT' => empty($body) ? $request->put($url) : $request->put($url, $body),
            default => $request->get($url),
        };

        // Rate limit: back off and retry once
        if ($response->status() === 429) {
            $retryAfter = (int) ($response->header('Retry-After') ?? 2);
            sleep(min($retryAfter, 5));

            $response = match (strtoupper($method)) {
                'GET' => $request->get($url),
                'POST' => $request->post($url, $body),
                'PUT' => empty($body) ? $request->put($url) : $request->put($url, $body),
                default => $request->get($url),
            };

            if ($response->status() === 429) {
                return [];
            }
        }

        if ($response->noContent()) {
            return [];
        }

        return $response->json() ?? [];
    }
}
