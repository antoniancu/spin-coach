# CO-001 — Session Duration: Gaps & Fixes

**Status:** Pre-build  
**Affects:** CLAUDE.md, docs/api.md, docs/workouts.md, docs/streetview.md, workouts.json  
**Summary:** Duration selection was planned at the data level but four implementation
gaps exist: the picker UI flow, Street View speed calibration + lap tracking,
WorkoutSeeder flattening logic, and the API preview query.

---

## Gap 1 — Home screen picker: intensity + duration selection flow

### Problem
`CLAUDE.md` describes `home.blade.php` as an "intensity + duration picker" but
doesn't define how the two choices are presented or sequenced.

### Resolution
**Two-step sequential** — duration picker reveals inline after intensity is
chosen. No page transition. Both selections must be made before Start appears.

```
┌─────────────────────────────────────┐
│  How are you feeling today?         │
│                                     │
│  [ 🟢  Easy   ]  ← full-width tap  │
│  [ 🟡  Medium ]                     │
│  [ 🔴  Hard   ]                     │
│                                     │
│  ▼ reveals after intensity chosen   │
│                                     │
│  How long?                          │
│  [ 20 min ]  [ 30 min ]  [ 45 min ] │
│                                     │
│  ▼ reveals after duration chosen    │
│                                     │
│  Steady Endurance Ride              │  ← workout name/desc preview
│  A long steady effort at easy pace  │    fetched from API
│                                     │
│  [ Choose a Route → ]   (optional)  │
│  [ Connect Spotify  ]   (optional)  │
│                                     │
│  [ ▶  START RIDE ]                  │  ← only appears when both chosen
└─────────────────────────────────────┘
```

State managed in JS on the page — no round trips until Start is pressed.
On intensity + duration selection: fire `GET /api/workouts?intensity=easy&duration=30`
to fetch the workout preview (name + description only).
On "START RIDE": POST to `/api/workout/start` with `intensity`, `duration_planned_min`,
`workout_id` (from preview), optionally `virtual_route_id` and `spotify_playlist_uri`.
On success: redirect to `/ride/{session_id}`.

---

## Gap 2 — Street View: lap tracking for multi-loop sessions

### Problem
Simulated speed (no BLE) is fixed per phase type — duration has no effect on
speed, the route just loops when complete. This is correct behaviour, but the
UI has no awareness of laps. A 45-min ride on a 12km route will complete the
loop multiple times with no feedback.

### Resolution
Speed calibration stays as-is. Add lap tracking to `streetView.js`:

```js
let lapCount = 0;

// When route end is reached in svTick():
if (nextWaypointIndex >= waypoints.length) {
    lapCount++;
    distanceCovered = 0;
    nextWaypointIndex = 0;
    updateLapIndicator(lapCount + 1); // "Lap 2", "Lap 3" etc.
}
```

Show in ride overlay: total virtual distance across all laps:
`totalVirtualKm = (lapCount × route.total_distance_km) + currentLapKm`

Add to POST `/api/workout/{session_id}/finish` payload:
```json
{
  "laps_completed": 2,
  "total_virtual_distance_km": 24.8
}
```

Add two columns to `workout_sessions` table:

| column                    | type    | constraints         | notes                      |
|---------------------------|---------|---------------------|----------------------------|
| laps_completed            | integer | nullable, default 0 | Street View laps completed |
| total_virtual_distance_km | float   | nullable            | Cumulative across all laps |

These also appear on the post-ride history/show screen as a fun stat.

---

## Gap 3 — WorkoutSeeder must flatten the variants structure

### Problem
`workouts.json` uses a nested `variants` array (one workout parent, three
duration children). The `workouts` DB table is flat — one row per variant.
The seeder was never given explicit flattening instructions.

### Resolution
`WorkoutSeeder` must flatten on read:

```php
$data = json_decode(
    file_get_contents(base_path('blueprints/workouts.json')), true
);

foreach ($data['workouts'] as $workout) {
    foreach ($workout['variants'] as $variant) {
        Workout::create([
            'name'         => $workout['name'],
            'intensity'    => $workout['intensity'],
            'description'  => $workout['description'] ?? null,
            'duration_min' => $variant['duration_min'],
            'sort_order'   => $variant['sort_order'],
            'phases'       => $variant['phases'],
        ]);
    }
}
```

Result: 27 rows (9 workouts × 3 duration variants). Correct.

---

## Gap 4 — API needs a preview endpoint for the picker

### Problem
After intensity + duration are both selected, the home screen needs to
show the workout name and description. No endpoint was specified for this.

### Resolution
Add query param combination to `GET /api/workouts`:
- `?intensity=easy&duration=30` → returns the single best-match workout

```json
{
  "data": {
    "id": 2,
    "name": "Steady Endurance Ride",
    "description": "A long, steady effort at comfortable pace.",
    "duration_min": 30,
    "intensity": "easy",
    "phase_count": 3
  },
  "error": null
}
```

The `workout_id` from this response is stored in JS and sent with
`/api/workout/start` so the session is linked to the template row.

---

## Simulated speed reference (no change — confirmed correct)

| Phase    | Simulated speed | Feel            |
|----------|-----------------|-----------------|
| warmup   | 4.5 m/s         | ~16 km/h        |
| work     | 7.5 m/s         | ~27 km/h        |
| rest     | 3.5 m/s         | ~13 km/h        |
| cooldown | 3.0 m/s         | ~11 km/h        |

These feel natural regardless of duration. The route loops — that's intentional.

---

## Files to update before Claude Code starts

| File | Change needed |
|---|---|
| `CLAUDE.md` | Add WorkoutSeeder flattening note in conventions section |
| `docs/database.md` | Add `laps_completed` + `total_virtual_distance_km` to workout_sessions table |
| `docs/api.md` | Add `?intensity=&duration=` query to GET /api/workouts; add lap fields to finish payload |
| `docs/workouts.md` | Add home screen picker spec |
| `docs/streetview.md` | Add lap counter and updateLapIndicator spec |
| `workouts.json` | No change — structure is correct, seeder handles it |
