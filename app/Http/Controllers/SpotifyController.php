<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\SpotifyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SpotifyController extends Controller
{
    public function __construct(
        private readonly SpotifyService $spotify,
    ) {}

    // --- Web routes (OAuth) ---

    public function redirect(): RedirectResponse
    {
        $scopes = 'user-read-playback-state user-modify-playback-state user-read-currently-playing playlist-read-private';
        $state = bin2hex(random_bytes(16));
        session(['spotify_state' => $state]);

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.spotify.client_id'),
            'scope' => $scopes,
            'redirect_uri' => config('services.spotify.redirect_uri'),
            'state' => $state,
        ]);

        return redirect('https://accounts.spotify.com/authorize?' . $query);
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->input('state') !== session('spotify_state')) {
            return redirect('/settings')->with('error', 'Invalid OAuth state.');
        }

        session()->forget('spotify_state');

        if ($request->has('error')) {
            return redirect('/settings')->with('error', 'Spotify authorization denied.');
        }

        $this->spotify->exchangeCode($request->input('code'));

        return redirect('/settings')->with('success', 'Spotify connected!');
    }

    // --- API routes ---

    public function apiStatus(): JsonResponse
    {
        return response()->json([
            'data' => [
                'connected' => $this->spotify->isConnected(),
                'expires_at' => $this->spotify->getExpiresAt(),
            ],
            'error' => null,
        ]);
    }

    public function apiDevices(): JsonResponse
    {
        try {
            $devices = $this->spotify->getDevices();
            return response()->json(['data' => ['devices' => $devices], 'error' => null]);
        } catch (\RuntimeException $e) {
            return response()->json(['data' => null, 'error' => $e->getMessage()], 503);
        }
    }

    public function apiNowPlaying(): JsonResponse
    {
        try {
            $track = $this->spotify->getNowPlaying();
            return response()->json([
                'data' => [
                    'track' => $track,
                    'is_playing' => $track !== null,
                ],
                'error' => null,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['data' => null, 'error' => $e->getMessage()], 503);
        }
    }

    public function apiPlay(Request $request): JsonResponse
    {
        try {
            $this->spotify->play(
                $request->input('device_id'),
                $request->input('context_uri'),
                (int) $request->input('volume_percent', 80),
            );
            return response()->json(['data' => ['playing' => true], 'error' => null]);
        } catch (\RuntimeException $e) {
            return response()->json(['data' => null, 'error' => $e->getMessage()], 503);
        }
    }

    public function apiPause(Request $request): JsonResponse
    {
        try {
            $this->spotify->pause($request->input('device_id'));
            return response()->json(['data' => ['playing' => false], 'error' => null]);
        } catch (\RuntimeException $e) {
            return response()->json(['data' => null, 'error' => $e->getMessage()], 503);
        }
    }

    public function apiNext(Request $request): JsonResponse
    {
        try {
            $this->spotify->next($request->input('device_id'));
            return response()->json(['data' => null, 'error' => null]);
        } catch (\RuntimeException $e) {
            return response()->json(['data' => null, 'error' => $e->getMessage()], 503);
        }
    }

    public function apiVolume(Request $request): JsonResponse
    {
        $request->validate(['volume_percent' => 'required|integer|min:0|max:100']);

        try {
            $this->spotify->setVolume(
                (int) $request->input('volume_percent'),
                $request->input('device_id'),
            );
            return response()->json(['data' => null, 'error' => null]);
        } catch (\RuntimeException $e) {
            return response()->json(['data' => null, 'error' => $e->getMessage()], 503);
        }
    }
}
