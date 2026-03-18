# SpinCoach — Database Schema

## Engine
MariaDB 11.2, running in Docker on norford (see CLAUDE.md for compose config).
Connection: `127.0.0.1:3306`, database `spincoach`.

Native MariaDB types in use:
- `->enum()` for constrained string columns
- `->json()` for structured data (phases, waypoints)
- `->boolean()` maps to TINYINT(1)
- Standard `->string()`, `->text()`, `->integer()`, `->float()`, `->timestamp()`

No SQLite workarounds needed. Use the full MariaDB type set.

---

## Tables

### users
The profile selector. No auth. No passwords.

| column       | type    | constraints                 | notes                            |
|--------------|---------|-----------------------------|----------------------------------|
| id           | bigint  | PK, autoincrement           |                                  |
| name         | string  | not null                    | Display name, e.g. "Marc"        |
| avatar_emoji | string  | not null, default '🚴'      | Single emoji character           |
| color_hex    | string  | not null, default '#7C3AED' | Hex color for UI accents         |
| created_at   | timestamp | nullable                  |                                  |
| updated_at   | timestamp | nullable                  |                                  |

Model: `App\Models\User`
Seeder: none — users are created at runtime via the profile picker

---

### workouts
Predefined workout templates. Seeded from `public/data/workouts.json`.
Read-only at runtime — the library of available sessions.

| column       | type    | constraints           | notes                                  |
|--------------|---------|-----------------------|----------------------------------------|
| id           | bigint  | PK, autoincrement     |                                        |
| name         | string  | not null              | e.g. "Steady Endurance Ride"           |
| intensity    | enum    | not null              | 'easy', 'medium', 'hard'               |
| duration_min | integer | not null              | Total planned duration in minutes      |
| description  | text    | nullable              | Short description shown on picker      |
| phases       | json    | not null              | Array of phase objects — see below     |
| sort_order   | integer | not null, default 0   | Display order within intensity group   |
| created_at   | timestamp | nullable            |                                        |
| updated_at   | timestamp | nullable            |                                        |

Model cast: `['phases' => 'array']`

#### Phase schema (stored in `phases` JSON column)
Each workout is an ordered array of phase objects iterated by the timer engine.

```json
[
  {
    "type": "warmup",
    "label": "Warm up easy",
    "duration_sec": 300,
    "rpm_low": 70,
    "rpm_high": 85,
    "resistance": 3,
    "audio_cue": "Settle in, easy pace"
  },
  {
    "type": "work",
    "label": "Tempo effort",
    "duration_sec": 600,
    "rpm_low": 88,
    "rpm_high": 95,
    "resistance": 6,
    "audio_cue": "Increase resistance, push the pace"
  },
  {
    "type": "rest",
    "label": "Recovery spin",
    "duration_sec": 180,
    "rpm_low": 75,
    "rpm_high": 85,
    "resistance": 3,
    "audio_cue": "Back off, easy spin"
  },
  {
    "type": "cooldown",
    "label": "Cool down",
    "duration_sec": 300,
    "rpm_low": 65,
    "rpm_high": 80,
    "resistance": 2,
    "audio_cue": "Wind it down, stretch incoming"
  }
]
```

Phase `type` enum: `warmup` | `work` | `rest` | `cooldown`
All durations in seconds. `resistance` is 1–10 (maps to C6's 100-level scale × 10).
`audio_cue` spoken via `window.speechSynthesis` at phase start.

---

### workout_sessions
One row per ride. Created on "Start", updated throughout, finalised on end/exit.

| column               | type      | constraints                        | notes                                    |
|----------------------|-----------|------------------------------------|------------------------------------------|
| id                   | bigint    | PK, autoincrement                  |                                          |
| user_id              | bigint    | FK users.id, not null, cascade del |                                          |
| workout_id           | bigint    | FK workouts.id, nullable           | Null if ad-hoc session                   |
| virtual_route_id     | bigint    | FK virtual_routes.id, nullable     | Null if no Street View route chosen      |
| intensity            | enum      | not null                           | 'easy', 'medium', 'hard'                 |
| duration_planned_min | integer   | not null                           | Duration selected before starting        |
| duration_actual_sec  | integer   | nullable                           | Wall-clock seconds start→end             |
| started_at           | timestamp | not null                           |                                          |
| ended_at             | timestamp | nullable                           | Null until session finishes              |
| completed            | boolean   | not null, default false            | True only if all phases finished         |
| avg_cadence_rpm      | integer   | nullable                           | Computed on finish from interval rows    |
| avg_heart_rate_bpm   | integer   | nullable                           | Null if no HR monitor connected          |
| peak_heart_rate_bpm  | integer   | nullable                           |                                          |
| calories_estimate    | integer   | nullable                           | MET × weight × hours (rough)             |
| distance_km_estimate | float     | nullable                           | Derived from cadence × assumed wheel dia |
| spotify_playlist_uri | string    | nullable                           | URI queued for this session              |
| notes                | text      | nullable                           | Free text, editable post-session         |
| laps_completed            | integer   | nullable, default 0                | Street View route laps completed         |
| total_virtual_distance_km | float     | nullable                           | Cumulative km across all laps            |
| laps_completed       | integer   | nullable, default 0                | Street View route laps completed         |
| total_virtual_distance_km | float | nullable                          | Cumulative km across all laps            |
| created_at           | timestamp | nullable                           |                                          |
| updated_at           | timestamp | nullable                           |                                          |

Model relationships:
- `belongsTo User`
- `belongsTo Workout`
- `belongsTo VirtualRoute`
- `hasMany SessionInterval`

---

### session_intervals
One row per completed phase within a session. Write-once — no updates after creation.
Written by JS timer via `POST /api/workout/{session}/interval` as each phase ends.

| column              | type    | constraints                         | notes                                    |
|---------------------|---------|-------------------------------------|------------------------------------------|
| id                  | bigint  | PK, autoincrement                   |                                          |
| session_id          | bigint  | FK workout_sessions.id, cascade del |                                          |
| sequence            | integer | not null                            | 0-indexed order within session           |
| phase_type          | enum    | not null                            | 'warmup', 'work', 'rest', 'cooldown'     |
| target_rpm_low      | integer | not null                            | From phase definition                    |
| target_rpm_high     | integer | not null                            |                                          |
| target_resistance   | integer | not null                            | 1–10                                     |
| duration_sec        | integer | not null                            | Planned duration                         |
| actual_duration_sec | integer | nullable                            | How long it actually ran                 |
| avg_cadence_rpm     | integer | nullable                            | Null if BLE bridge not connected         |
| avg_heart_rate_bpm  | integer | nullable                            | Null if no HR monitor                    |
| created_at          | timestamp | nullable                          |                                          |

No `updated_at` — intervals are write-once.

---

### virtual_routes
Pre-curated cycling routes for the Street View visualization.
Seeded from `public/data/routes.json`. Not user-editable in v1.

| column            | type    | constraints           | notes                                              |
|-------------------|---------|-----------------------|----------------------------------------------------|
| id                | bigint  | PK, autoincrement     |                                                    |
| name              | string  | not null              | e.g. "Amsterdam Canal Ring"                        |
| description       | text    | nullable              | Shown on route browser card                        |
| location_type     | enum    | not null              | 'city', 'mountain', 'forest', 'coastal', 'boardwalk' |
| country           | string  | not null              | e.g. "Netherlands"                                 |
| region            | string  | nullable              | e.g. "Noord-Holland"                               |
| difficulty        | enum    | not null              | 'flat', 'rolling', 'hilly'                         |
| total_distance_km | float   | not null              |                                                    |
| elevation_gain_m  | integer | not null, default 0   |                                                    |
| waypoints         | json    | not null              | Array of waypoint objects — see below              |
| thumbnail_url     | string  | nullable              | Static Street View image for card preview          |
| active            | boolean | not null, default true | Set false to hide without deleting                |
| sort_order        | integer | not null, default 0   |                                                    |
| created_at        | timestamp | nullable            |                                                    |
| updated_at        | timestamp | nullable            |                                                    |

Model cast: `['waypoints' => 'array']`

#### Waypoint schema (stored in `waypoints` JSON column)
Pre-computed. `heading` is bearing from this point to the next — panorama always
faces forward. `pano_id` is pre-fetched via Street View API and cached here.

```json
[
  {
    "lat": 52.3676,
    "lng": 4.9041,
    "heading": 312.4,
    "pitch": -2,
    "pano_id": "CAoSLEFGMVFpcE...",
    "distance_from_start_m": 0
  },
  {
    "lat": 52.3689,
    "lng": 4.9028,
    "heading": 318.1,
    "pitch": -1,
    "pano_id": "CAoSLEFGMVFpcN...",
    "distance_from_start_m": 47
  }
]
```

Waypoint spacing: ~40–60m. `distance_from_start_m` is cumulative.
Street View JS driver compares session `distanceCovered` against this to advance.
`pano_id` is null after seeding — populated by `php artisan routes:fetch-panos`.

---

### spotify_tokens
Single-row table. Upserted on OAuth callback and every token refresh.
Never more than one row. Spotify account ≠ SpinCoach user.

| column        | type      | constraints | notes                                      |
|---------------|-----------|-------------|--------------------------------------------|
| id            | bigint    | PK          | Always 1                                   |
| access_token  | text      | not null    | Short-lived (~1hr)                         |
| refresh_token | text      | not null    | Long-lived, used to obtain new access token |
| expires_at    | timestamp | not null    | SpotifyService checks this before API calls |
| scope         | string    | not null    | Space-separated granted scopes             |
| created_at    | timestamp | nullable    |                                            |
| updated_at    | timestamp | nullable    |                                            |

SpotifyService refresh logic:
- Before every Spotify API call, check `expires_at < now() + 60 seconds`
- If expiring: POST to Spotify token refresh endpoint, upsert row
- If no row: redirect to `/settings` with `?spotify=connect` flash message

Required OAuth scopes:
```
user-read-playback-state
user-modify-playback-state
user-read-currently-playing
playlist-read-private
```

---

## Relationships summary

```
users
  └── has many workout_sessions

workouts
  └── has many workout_sessions

workout_sessions
  ├── belongs to users
  ├── belongs to workouts        (nullable)
  ├── belongs to virtual_routes  (nullable)
  └── has many session_intervals

session_intervals
  └── belongs to workout_sessions

virtual_routes
  └── has many workout_sessions
```

---

## Seeders

### WorkoutSeeder
Reads `public/data/workouts.json`. Truncates `workouts` and re-seeds on each run.
Creates 9 workout templates: 3 per intensity (easy / medium / hard),
each in 3 durations (20, 30, 45 min). Phases scale proportionally —
warmup and cooldown fixed at 5 min each, work/rest intervals fill the middle.

### RouteSeeder
Reads `public/data/routes.json`. Truncates `virtual_routes` and re-seeds.
Seeds 6 routes (Amsterdam canals, Col du Tourmalet, Kyoto Arashiyama,
Vancouver Sea to Sky, New York Central Park, Mallorca Sa Calobra).
Sets `pano_id: null` on all waypoints — does not call Google Maps API.
Run `php artisan routes:fetch-panos` after seeding to populate pano IDs.

---

## Indexes

```php
// workout_sessions
$table->index(['user_id', 'started_at']);  // history queries sorted by date
$table->index('completed');                 // filter finished sessions only

// session_intervals
$table->index(['session_id', 'sequence']); // fetch all intervals in order
```

---

## Migration order
Must follow this order to satisfy FK constraints:

1. `create_users_table`
2. `create_workouts_table`
3. `create_virtual_routes_table`
4. `create_workout_sessions_table`   ← FKs: users, workouts, virtual_routes
5. `create_session_intervals_table`  ← FK: workout_sessions
6. `create_spotify_tokens_table`
