# SpinCoach — Workout Engine

## Overview

The workout engine is split across two layers:

- **Server (PHP)**: `WorkoutEngine` service resolves workout definitions,
  creates sessions, and validates interval logs.
- **Client (JS)**: `workoutPlayer.js` drives the timer, sequences phases,
  fires audio cues, calls the API, and controls the UI.

---


## Home Screen Picker Flow

The home screen (`home.blade.php`) uses a two-step sequential reveal.
State is managed in JS — no round trips until Start is pressed.

**Step 1 — Intensity** (always visible):
Three full-width tap targets: Easy / Medium / Hard.
On selection: fires `GET /api/workouts?intensity={x}&duration={last_duration}`
if a duration was previously chosen, else waits for Step 2.

**Step 2 — Duration** (reveals after intensity chosen):
Three equal-width tap targets: 20 min / 30 min / 45 min.
On selection: fires `GET /api/workouts?intensity={x}&duration={y}` and
displays the returned workout name and description as a preview below.
The `workout_id` from the response is stored in JS for the start payload.

**Step 3 — Optional extras** (reveal after duration chosen):
- "Choose a Route →" links to `/routes` which passes selection back via query param
- "Connect Spotify" links to `/settings#spotify` if not connected

**START RIDE button** — appears after both intensity and duration are chosen.
On tap: POST `/api/workout/start` with `intensity`, `duration_planned_min`,
`workout_id`, and optional `virtual_route_id` + `spotify_playlist_uri`.
On 200: JS redirects to `/ride/{session_id}`.

---
## Timer Engine — workoutPlayer.js

Uses `requestAnimationFrame` (not `setInterval`) for accuracy.
Clock drift is corrected on every frame by comparing wall-clock elapsed
time against expected phase position.

### State machine

```
IDLE → STARTING → WARMUP → [WORK → REST]* → COOLDOWN → FINISHED
                                              ↓ (user exits early)
                                           ABORTED
```

### Core loop

```js
// Simplified
function tick(timestamp) {
  const elapsed = timestamp - phaseStartTime;
  const remaining = (currentPhase.duration_sec * 1000) - elapsed;

  updateDisplay(remaining);

  if (remaining <= 5000 && !warningFired) {
    audioWarning();            // 5-second beep before transition
    warningFired = true;
  }

  if (remaining <= 0) {
    completePhase();           // logs interval, advances to next phase
  } else {
    requestAnimationFrame(tick);
  }
}
```

### Phase completion

On each phase end:
1. POST to `/api/workout/{session_id}/interval` with actual metrics
2. If BLE bridge connected: include `avg_cadence_rpm` and `avg_heart_rate_bpm`
   (averaged from the rolling buffer in `bleClient.js`)
3. Advance `currentPhaseIndex`
4. If more phases remain: start next phase, fire audio cue
5. If last phase: call `finishSession()`

### Screen Wake Lock

Acquired at session start, released at session end/abort:
```js
let wakeLock = null;
async function acquireWakeLock() {
  wakeLock = await navigator.wakeLock.request('screen');
}
```
Re-acquired on `visibilitychange` if page becomes visible mid-session.

---

## Audio Cues — audio.js

Two cue types:

**Transition beeps** — Web Audio API, no files needed:
```js
function beep(freq = 880, duration = 0.15, volume = 0.6) {
  const ctx = new AudioContext();
  const osc = ctx.createOscillator();
  const gain = ctx.createGain();
  osc.connect(gain);
  gain.connect(ctx.destination);
  osc.frequency.value = freq;
  gain.gain.setValueAtTime(volume, ctx.currentTime);
  gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + duration);
  osc.start();
  osc.stop(ctx.currentTime + duration);
}

// 5 sec warning: single beep at 660Hz
// Phase transition: double beep at 880Hz
// Session complete: three ascending beeps
```

**Voice cues** — Web Speech API, speaks the `audio_cue` field from the phase:
```js
function speak(text) {
  const utterance = new SpeechSynthesisUtterance(text);
  utterance.rate = 1.0;
  utterance.pitch = 1.0;
  utterance.volume = 1.0;
  window.speechSynthesis.speak(utterance);
}
```
Called at the start of each phase with `phase.audio_cue`.

---

## Intensity Levels & Training Zones

### Easy — Zone 2 (aerobic base)
- RPM target: 80–95
- Resistance: 2–4 / 10
- HR zone: 60–75% max
- Goal: build aerobic base, active recovery, long steady effort
- Spotify BPM target: 70–95 BPM

### Medium — Zone 3–4 (tempo / threshold)
- RPM target: 85–100
- Resistance: 5–7 / 10
- HR zone: 75–88% max
- Goal: lactate threshold development, sustained power
- Spotify BPM target: 100–125 BPM

### Hard — Zone 4–5 (VO2 max / HIIT)
- RPM target: 85–110 (sprint phases up to 115)
- Resistance: 7–9 / 10
- HR zone: 88–100% max
- Goal: VO2 max, anaerobic capacity, power intervals
- Spotify BPM target: 125–155 BPM

---

## Workout Library Structure

9 templates total — 3 per intensity level, each in 3 durations (20 / 30 / 45 min).
Phases scale proportionally: warmup and cooldown are always 5 min each.
Work and rest intervals fill the middle, maintaining the same work:rest ratio.

### Easy workouts

**Steady Endurance Ride**
Structure: warmup → long steady work → cooldown
Work:rest ratio: no rest blocks — single sustained effort
Resistance constant at 3–4 throughout work phase.

**High Cadence Recovery**
Structure: warmup → 90–100 RPM spin at low resistance → cooldown
Focus: pedaling efficiency and neuromuscular adaptation
Resistance stays at 2 during work phase — speed not power.

**Zone 2 Cruise**
Structure: warmup → alternating 5-min low-cadence (60 RPM) / high-cadence (95 RPM) blocks → cooldown
Same resistance throughout, only cadence varies.

### Medium workouts

**Tempo Blocks**
Structure: warmup → 3 × sustained tempo efforts with short recovery → cooldown
Work: 88–95 RPM at resistance 6. Rest: 75–85 RPM at resistance 3.

**Sweet Spot**
Structure: warmup → 2 × long threshold efforts → cooldown
Effort level: just below lactate threshold, sustainable but challenging.
Work: 90–98 RPM at resistance 6–7.

**Pyramid**
Structure: warmup → intervals that build then taper (2→4→6→4→2 min) → cooldown
Resistance increases with interval length. Forces pacing discipline.

### Hard workouts

**Power Intervals**
Structure: warmup → 4 × 60-sec all-out sprints with 90-sec recovery → cooldown
Maximum effort during work phases. 2 sets for beginners, 4 for advanced.

**Classic HIIT**
Structure: warmup → 3 × 2-min hard efforts with 5-min recovery → cooldown
Work: 90–100 RPM at resistance 8. Rest: 75 RPM at resistance 3.

**Ladder Sprints**
Structure: warmup → 30s / 45s / 60s / 45s / 30s sprints with equal rest → cooldown
Ascending then descending ladder. Equal work:rest ratio throughout.

---

## Resistance Mapping

The C6 has 100 micro-adjustable resistance levels. The app uses a 1–10 scale
that maps to approximate bands:

| App level | C6 range | Feel               |
|-----------|----------|--------------------|
| 1         | 1–10     | Freewheeling       |
| 2         | 11–20    | Very easy          |
| 3         | 21–30    | Easy warmup        |
| 4         | 31–40    | Light effort       |
| 5         | 41–50    | Moderate           |
| 6         | 51–60    | Tempo              |
| 7         | 61–70    | Hard               |
| 8         | 71–80    | Very hard          |
| 9         | 81–90    | Near max           |
| 10        | 91–100   | Max effort         |

Displayed to user as a dial cue: "Set resistance to 6" — user adjusts manually.
The C6 resistance is not software-controllable (no ERG mode).
