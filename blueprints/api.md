# SpinCoach — API Reference

## Conventions

All API routes live in `routes/api.php`.
All responses follow:
```json
{ "data": {}, "error": null }
{ "data": null, "error": "human-readable message" }
```

All API routes are protected by `CurrentUser` middleware — returns
`{ "data": null, "error": "No active rider selected" }` with HTTP 401
if no `user_id` in session.

Base URL: `https://spin.norford.home/api`

---

## Users

### GET /api/users
Returns all users for the profile picker.
```json
{
  "data": [
    { "id": 1, "name": "Marc", "avatar_emoji": "🚴", "color_hex": "#7C3AED" }
  ],
  "error": null
}
```

### POST /api/users
Create a new rider profile.
Request:
```json
{ "name": "Sophie", "avatar_emoji": "🏃", "color_hex": "#059669" }
```
Response: `{ "data": { "id": 2, "name": "Sophie", ... }, "error": null }`
Validation: `name` required max 32 chars, `avatar_emoji` required, `color_hex` required valid hex.

### POST /api/users/select
Set the active rider for this session.
Request: `{ "user_id": 1 }`
Response: `{ "data": { "user": { ... } }, "error": null }`
Sets `session('user_id')`. Redirects are handled by web routes, not this endpoint.

### POST /api/users/deselect
Clear active rider from session (switch rider).
Request: none
Response: `{ "data": null, "error": null }`

---

## Workouts

### GET /api/workouts
Returns all workout templates, grouped by intensity.
Query params:
- `intensity` (optional): filter to `easy` | `medium` | `hard`
- `duration` (optional): filter to `20` | `30` | `45`
- When BOTH `intensity` and `duration` are provided: returns a single
  best-match workout object (for the home screen picker preview).
  Response shape: `{ data: { id, name, description, duration_min, intensity, phase_count } }`

```json
{
  "data": {
    "easy": [ { "id": 1, "name": "Steady Endurance", "duration_min": 30, "phases": [...] } ],
    "medium": [ ... ],
    "hard": [ ... ]
  },
  "error": null
}
```

### GET /api/workouts/{id}
Returns a single workout template with full phases array.
```json
{
  "data": {
    "id": 1,
    "name": "Steady Endurance Ride",
    "intensity": "easy",
    "duration_min": 30,
    "description": "...",
    "phases": [
      {
        "type": "warmup",
        "label": "Warm up easy",
        "duration_sec": 300,
        "rpm_low": 70, "rpm_high": 85,
        "resistance": 3,
        "audio_cue": "Settle in, easy pace"
      }
    ]
  },
  "error": null
}
```

---

## Workout Sessions

### POST /api/workout/start
Called when user taps Start. Creates a session row.
Request:
```json
{
  "workout_id": 1,
  "intensity": "easy",
  "duration_planned_min": 30,
  "virtual_route_id": 2,
  "spotify_playlist_uri": "spotify:playlist:37i9dQZF1DX..."
}
```
`workout_id`, `virtual_route_id`, and `spotify_playlist_uri` are all optional.
Response:
```json
{
  "data": {
    "session_id": 42,
    "started_at": "2025-03-18T14:00:00Z"
  },
  "error": null
}
```
This is the `session_id` the JS timer uses for all subsequent calls.

### POST /api/workout/{session_id}/interval
Called by JS timer as each phase completes.
Request:
```json
{
  "sequence": 0,
  "phase_type": "warmup",
  "target_rpm_low": 70,
  "target_rpm_high": 85,
  "target_resistance": 3,
  "duration_sec": 300,
  "actual_duration_sec": 312,
  "avg_cadence_rpm": 78,
  "avg_heart_rate_bpm": 112
}
```
`avg_cadence_rpm` and `avg_heart_rate_bpm` are nullable (null if BLE bridge not connected).
Response: `{ "data": { "interval_id": 17 }, "error": null }`

### POST /api/workout/{session_id}/finish
Called when session ends (all phases done, or user exits).
Request:
```json
{
  "completed": true,
  "duration_actual_sec": 1847,
  "avg_cadence_rpm": 84,
  "avg_heart_rate_bpm": 138,
  "peak_heart_rate_bpm": 162,
  "calories_estimate": 410,
  "distance_km_estimate": 18.4,
  "notes": null,
  "laps_completed": 2,
  "total_virtual_distance_km": 24.8
}
```
All fields optional/nullable. `completed: false` if user quit early.
`laps_completed` and `total_virtual_distance_km` are null if no route was selected.
Response: `{ "data": { "session_id": 42, "summary_url": "/history/42" }, "error": null }`

### GET /api/workout/{session_id}
Returns session detail for post-ride summary screen.
```json
{
  "data": {
    "session": { ... },
    "intervals": [ ... ],
    "workout": { ... }
  },
  "error": null
}
```

---

## History

### GET /api/history
Returns paginated session history for the current user.
Query params: `page` (default 1), `per_page` (default 20)
```json
{
  "data": {
    "sessions": [
      {
        "id": 42,
        "workout_name": "Steady Endurance Ride",
        "intensity": "easy",
        "started_at": "2025-03-18T14:00:00Z",
        "duration_actual_sec": 1847,
        "completed": true,
        "avg_cadence_rpm": 84,
        "calories_estimate": 410
      }
    ],
    "total": 47,
    "page": 1
  },
  "error": null
}
```

### PATCH /api/history/{session_id}/notes
Update the notes field on a completed session.
Request: `{ "notes": "Felt strong today" }`
Response: `{ "data": { "session_id": 42 }, "error": null }`
Only the owning user can update their own sessions (checked via `$request->user()`).

---

## Virtual Routes

### GET /api/routes
Returns all active virtual routes.
Query params: `difficulty` (optional), `location_type` (optional)
```json
{
  "data": [
    {
      "id": 1,
      "name": "Amsterdam Canal Ring",
      "location_type": "city",
      "country": "Netherlands",
      "difficulty": "flat",
      "total_distance_km": 12.4,
      "elevation_gain_m": 8,
      "thumbnail_url": "https://maps.googleapis.com/...",
      "description": "A gentle loop through Amsterdam's historic canal district."
    }
  ],
  "error": null
}
```

### GET /api/routes/{id}/waypoints
Returns the full waypoint array for a route.
Used by `streetView.js` at ride start to pre-load all pano IDs.
```json
{
  "data": {
    "route_id": 1,
    "name": "Amsterdam Canal Ring",
    "total_distance_km": 12.4,
    "waypoints": [
      {
        "lat": 52.3676,
        "lng": 4.9041,
        "heading": 312.4,
        "pitch": -2,
        "pano_id": "CAoSLEFGMVFpcE...",
        "distance_from_start_m": 0
      }
    ]
  },
  "error": null
}
```

---

## Spotify

### GET /api/spotify/status
Returns current Spotify connection state.
```json
{
  "data": {
    "connected": true,
    "expires_at": "2025-03-18T15:00:00Z"
  },
  "error": null
}
```

### GET /api/spotify/devices
Returns available Spotify Connect devices.
```json
{
  "data": {
    "devices": [
      { "id": "abc123", "name": "Marc's iPhone", "type": "Smartphone", "is_active": true },
      { "id": "def456", "name": "Mac Mini", "type": "Computer", "is_active": false }
    ]
  },
  "error": null
}
```

### POST /api/spotify/play
Start playback on a device.
Request:
```json
{
  "device_id": "abc123",
  "context_uri": "spotify:playlist:37i9dQZF1DX...",
  "volume_percent": 80
}
```
`device_id` optional — uses currently active device if omitted.
Response: `{ "data": { "playing": true }, "error": null }`

### POST /api/spotify/pause
Pause playback.
Request: none (or `{ "device_id": "abc123" }` to target specific device)
Response: `{ "data": { "playing": false }, "error": null }`

### POST /api/spotify/next
Skip to next track.
Response: `{ "data": null, "error": null }`

### PUT /api/spotify/volume
Set playback volume.
Request: `{ "volume_percent": 60 }`
`volume_percent`: integer 0–100.
Response: `{ "data": null, "error": null }`
Used for interval-based volume ducking (rest = 60%, work = 90%).

### GET /api/spotify/now-playing
Returns current track info for the ride overlay.
```json
{
  "data": {
    "track": {
      "name": "Power",
      "artist": "Kanye West",
      "album_art_url": "https://i.scdn.co/image/...",
      "duration_ms": 292000,
      "progress_ms": 45000
    },
    "is_playing": true
  },
  "error": null
}
```
Returns `{ "data": { "track": null, "is_playing": false } }` if nothing playing.

---

## Web Routes (Blade — not JSON)

| Method | Path                 | Controller                    | View                        |
|--------|----------------------|-------------------------------|-----------------------------|
| GET    | /                    | redirects to /select-user or /home |                        |
| GET    | /select-user         | UserController@select         | users/select.blade.php      |
| GET    | /users/create        | UserController@create         | users/create.blade.php      |
| GET    | /home                | WorkoutController@home        | home.blade.php              |
| GET    | /ride/{session_id}   | WorkoutController@ride        | ride.blade.php              |
| GET    | /routes              | RouteController@index         | routes/index.blade.php      |
| GET    | /history             | DashboardController@index     | history/index.blade.php     |
| GET    | /history/{id}        | DashboardController@show      | history/show.blade.php      |
| GET    | /settings            | SettingsController@index      | settings.blade.php          |
| GET    | /spotify/connect     | SpotifyController@redirect    | (redirects to Spotify)      |
| GET    | /spotify/callback    | SpotifyController@callback    | (redirects to /settings)    |

---

## Artisan Commands

| Command                        | Description                                              |
|--------------------------------|----------------------------------------------------------|
| `php artisan routes:fetch-panos` | Hits Street View API to populate `pano_id` on all waypoints. Run once after seeding. Requires `GOOGLE_MAPS_KEY` in `.env`. |
| `php artisan db:seed`          | Runs WorkoutSeeder + RouteSeeder                         |
| `php artisan migrate:fresh --seed` | Full reset — drops all tables, re-migrates, re-seeds |
