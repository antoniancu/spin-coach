# SpinCoach — Spotify Integration

## Overview

Spotify is controlled server-side via Laravel. The browser JS never holds
a token — it only calls `/api/spotify/*` routes which proxy to Spotify's API.
Token storage and refresh are handled entirely in `SpotifyService`.

Requires a **Spotify Premium** account (free accounts cannot use playback control).

---

## OAuth Flow

Uses Authorization Code flow (not Client Credentials — we need user-level
playback control scopes).

```
User visits /settings
  → clicks "Connect Spotify"
  → GET /spotify/connect
  → SpotifyController::redirect()
      builds Spotify authorize URL with scopes + state param
  → redirect to accounts.spotify.com/authorize

User approves in Spotify
  → Spotify redirects to /spotify/callback?code=...&state=...
  → SpotifyController::callback()
      validates state param
      exchanges code for access_token + refresh_token
      upserts spotify_tokens table (always row id=1)
  → redirect to /settings with success flash
```

### Required scopes
```
user-read-playback-state
user-modify-playback-state
user-read-currently-playing
playlist-read-private
```

### SpotifyController routes
```php
Route::get('/spotify/connect',  [SpotifyController::class, 'redirect']);
Route::get('/spotify/callback', [SpotifyController::class, 'callback']);
```
These are **web routes** (not API routes) because they involve redirects.

---

## SpotifyService

`app/Services/SpotifyService.php`

All public methods call `ensureFreshToken()` first, which:
1. Loads the `SpotifyToken` row (id=1)
2. If `expires_at < now() + 60 seconds`: calls the Spotify refresh endpoint
3. Updates the `spotify_tokens` row with new `access_token` and `expires_at`
4. Returns the fresh access token

```php
class SpotifyService
{
    public function isConnected(): bool
    public function getDevices(): array
    public function getNowPlaying(): ?array
    public function play(string $deviceId, string $contextUri, int $volume = 80): void
    public function pause(?string $deviceId = null): void
    public function next(?string $deviceId = null): void
    public function setVolume(int $percent, ?string $deviceId = null): void
    private function ensureFreshToken(): string
    private function request(string $method, string $endpoint, array $body = []): array
}
```

### HTTP client
Use Laravel's `Http` facade (Guzzle wrapper). All requests to:
`https://api.spotify.com/v1/`

With header: `Authorization: Bearer {access_token}`

---

## Playback Control

### Device targeting
All playback calls accept an optional `device_id`.
If omitted, Spotify uses the currently active device.
The settings screen shows a device picker (populated from `/api/spotify/devices`)
so the user can select their iPhone or HomePod before starting a ride.
Selected `device_id` is stored in `localStorage` on the client.

### Intensity → playlist mapping
Configured in `config/spincoach.php`:
```php
'spotify_playlists' => [
    'easy'   => env('SPOTIFY_PLAYLIST_EASY',   ''),
    'medium' => env('SPOTIFY_PLAYLIST_MEDIUM', ''),
    'hard'   => env('SPOTIFY_PLAYLIST_HARD',   ''),
],
```
User sets these playlist URIs in the `.env` (or via the settings screen).
When a session starts, `WorkoutController::start()` resolves the playlist
URI for the selected intensity and passes it to `SpotifyService::play()`.

Add to `.env`:
```
SPOTIFY_PLAYLIST_EASY=spotify:playlist:xxxxxxxx
SPOTIFY_PLAYLIST_MEDIUM=spotify:playlist:xxxxxxxx
SPOTIFY_PLAYLIST_HARD=spotify:playlist:xxxxxxxx
```

### Volume ducking
Called by `WorkoutController` when logging interval transitions:

| Phase     | Volume |
|-----------|--------|
| warmup    | 70%    |
| work      | 90%    |
| rest      | 55%    |
| cooldown  | 65%    |

`SpotifyService::setVolume()` is called server-side when the JS posts
`/api/workout/{session_id}/interval`. The `phase_type` in the request
determines the target volume.

---

## Settings Screen — spotify.js

`public/js/spotify.js` handles:
- Polling `/api/spotify/now-playing` every 10 seconds during a ride
  (updates the track name + artist overlay in the ride view)
- Play / pause / skip buttons in the ride overlay
- Device picker in settings
- Volume slider (maps 0–100 to PUT `/api/spotify/volume`)

### Now-playing overlay
Shown in the bottom-left of the ride screen.
Fades in when a track is active, fades out when nothing playing.
Updates every 10 seconds via `setInterval`.
Never blocks the timer or interferes with the workout engine.

---

## Error Handling

| Scenario | Behaviour |
|---|---|
| No token in DB | `/api/spotify/*` returns `{ "error": "Spotify not connected" }` with 503. JS shows "Connect Spotify" prompt in settings. |
| Token refresh fails | SpotifyService logs the error, returns 503. Does not crash the ride. |
| No active device | `play()` returns Spotify 404. API returns `{ "error": "No active Spotify device found" }`. JS shows toast: "Open Spotify on a device first." |
| Spotify API 429 (rate limit) | Back off 2 seconds, retry once. If still 429, skip the call silently. |
| Spotify Premium required | API returns 403. Show "Spotify Premium required" in settings. |
