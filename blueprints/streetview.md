# SpinCoach — Street View Visualization

## Overview

The Street View engine creates a "virtual ride" by advancing through a
pre-computed sequence of Google Street View panoramas as the user pedals.
Advancement speed is driven by cadence (from the BLE bridge) or a fixed
simulated speed if the bridge is not connected.

All Street View work happens client-side in `streetView.js`.
Laravel only serves the waypoint data from the DB.

---

## How it works

### Route loading
On ride start (when a route is selected):
1. JS calls `GET /api/routes/{id}/waypoints`
2. Receives array of `{ lat, lng, heading, pitch, pano_id, distance_from_start_m }`
3. All `pano_id` values are pre-fetched (by the `routes:fetch-panos` artisan
   command at setup time) — no runtime Maps API calls needed for pano lookup
4. Initialises `StreetViewPanorama` with the first waypoint's pano_id

### Ride loop
The Street View engine runs its own `requestAnimationFrame` loop alongside
the workout timer. On each frame:

```js
function svTick(timestamp) {
  const dt = (timestamp - lastTick) / 1000;  // seconds since last frame

  // Speed: from BLE cadence if connected, else fixed simulation
  const speed = bleConnected
    ? currentCadence * CADENCE_TO_SPEED   // ~0.08 m/s per RPM
    : simulatedSpeed(currentPhase);        // phase-based fallback

  distanceCovered += speed * dt;

  // Advance panorama when threshold crossed
  while (
    nextWaypointIndex < waypoints.length &&
    distanceCovered >= waypoints[nextWaypointIndex].distance_from_start_m
  ) {
    panorama.setPano(waypoints[nextWaypointIndex].pano_id);
    panorama.setPov({
      heading: waypoints[nextWaypointIndex].heading,
      pitch: waypoints[nextWaypointIndex].pitch
    });
    nextWaypointIndex++;
  }

  // Loop the route when it ends — track laps
  if (nextWaypointIndex >= waypoints.length) {
    lapCount++;
    distanceCovered = 0;
    nextWaypointIndex = 0;
    updateLapIndicator(lapCount + 1); // shows "Lap 2", "Lap 3" etc. in overlay
  }

  lastTick = timestamp;
  requestAnimationFrame(svTick);
}
```

### Speed calibration
`CADENCE_TO_SPEED = 0.08` (m/s per RPM) is the default.
At 90 RPM → 7.2 m/s → ~26 km/h (realistic for indoor cycling feel).
This constant is tunable in settings without a code change.

Simulated speed (no BLE) by phase:
| Phase    | Simulated speed |
|----------|-----------------|
| warmup   | 4.5 m/s (~16 km/h) |
| work     | 7.5 m/s (~27 km/h) |
| rest     | 3.5 m/s (~13 km/h) |
| cooldown | 3.0 m/s (~11 km/h) |

---

## Google Maps Setup

### API key
`GOOGLE_MAPS_KEY` in `.env` → `config('services.google.maps_key')`
Injected into Blade layout:
```blade
<script>
  window.GOOGLE_MAPS_KEY = "{{ config('services.google.maps_key') }}";
</script>
```
Then loaded in `ride.blade.php`:
```html
<script src="https://maps.googleapis.com/maps/api/js?key={{ config('services.google.maps_key') }}&callback=initStreetView" async defer></script>
```

### Required APIs to enable in Google Cloud Console
- Maps JavaScript API
- Street View Static API (for thumbnail URLs and pano ID pre-fetch)

### Cost
Street View panoramas in the JS SDK (`StreetViewPanorama` object) are billed
per load. Each time `setPano()` is called, it counts as one panorama load.
At ~40–60m spacing on a 12km route that's ~200–300 pano loads per ride.
Google's free tier: $200/month credit. At ~$0.014 per panorama load,
200 loads = ~$2.80. Well within free tier for personal use.

---

## Panorama Pre-fetch — routes:fetch-panos

Run once after seeding to populate `pano_id` on all waypoints:
```bash
php artisan routes:fetch-panos
```

This command:
1. Loads all `VirtualRoute` records where any waypoint has `pano_id: null`
2. For each waypoint, calls the Street View Static API metadata endpoint:
   `https://maps.googleapis.com/maps/api/streetview/metadata?location={lat},{lng}&key={key}`
3. Extracts the `pano_id` from the response
4. Updates the waypoint in the JSON array and saves back to the DB
5. Logs progress: `Route 1/6: Amsterdam... waypoint 47/210 done`

Uses a 50ms delay between requests to avoid rate limiting.
Idempotent — skips waypoints that already have a `pano_id`.

---

## YouTube Fallback

When a route's area has poor Street View coverage (trails, some forests,
private roads), the engine falls back to a YouTube cycling video.

Detection: if `pano_id` is null on more than 30% of waypoints after
running `routes:fetch-panos`, the route is flagged `use_youtube: true`
in its JSON and a `youtube_video_id` is stored instead of waypoints.

YouTube playback in ride view:
- Embedded via YouTube IFrame API (`<iframe>` with `enablejsapi=1`)
- Playback rate mapped to current phase speed:
  - warmup/cooldown: `player.setPlaybackRate(0.75)`
  - rest: `player.setPlaybackRate(0.75)`
  - work: `player.setPlaybackRate(1.0)` to `1.5` (scaled by cadence)
- Rate changes fire on phase transitions

YouTube videos for the seed routes:
- Forest / off-road routes: 4K POV cycling footage, search YouTube for
  `"4K cycling [location] POV"` and store the video ID

---

## Panorama Controls

Users can interact with the panorama during a ride:
- Pan left/right (touch drag)
- The heading auto-corrects back to the route heading 3 seconds after
  the user stops interacting (`pov_changed` listener + debounce)

Hidden controls (removed from default Panorama UI):
```js
const panorama = new google.maps.StreetViewPanorama(el, {
  linksControl: false,      // hide forward/back arrows
  panControl: false,        // hide pan widget
  zoomControl: false,       // hide zoom
  addressControl: false,    // hide location label
  fullscreenControl: false,
  motionTrackingControl: false,
  showRoadLabels: false,
});
```

---

## Curated Seed Routes

| # | Name | Country | Type | Distance | Difficulty |
|---|------|---------|------|----------|------------|
| 1 | Amsterdam Canal Ring | Netherlands | city | 12.4 km | flat |
| 2 | Col du Tourmalet | France | mountain | 19.0 km | hilly |
| 3 | Kyoto Arashiyama | Japan | forest | 8.2 km | flat |
| 4 | Vancouver Sea to Sky | Canada | coastal | 20.5 km | rolling |
| 5 | New York Central Park | USA | city | 9.7 km | rolling |
| 6 | Mallorca Sa Calobra | Spain | mountain | 9.4 km | hilly |

Waypoints for each are defined in `public/data/routes.json`.
