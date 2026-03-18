@extends('layouts.app')

@section('title', 'Ride — SpinCoach')

@section('content')
<div class="ride-display" id="ride-display">
    <a href="/settings?back=/ride/{{ $session->id }}" class="ride-settings-link" aria-label="Settings">&#9881;</a>
    <div class="ride-phase-label" id="phase-type">LOADING</div>
    <div class="ride-phase-name" id="phase-label">Preparing...</div>
    <div class="ride-timer" id="timer">--:--</div>
    <div class="ride-cadence" id="cadence-target"></div>
    <div class="resistance-control" id="resistance-control">
        <button class="res-btn res-minus" onclick="adjustResistance(-1)" aria-label="Decrease">−</button>
        <div class="res-display" id="resistance-display">
            <span class="res-actual" id="res-actual">--</span>
            <span class="res-label">/100</span>
            <span class="res-target" id="res-target-hint"></span>
        </div>
        <button class="res-btn res-plus" onclick="adjustResistance(1)" aria-label="Increase">+</button>
    </div>

    <div class="ble-live">
        <div class="ble-metric">
            <span class="ble-metric-value" id="live-cadence">--</span>
            <span class="ble-metric-label">RPM</span>
        </div>
        <div class="ble-zone" id="cadence-zone">--</div>
        <div class="ble-metric">
            <span class="ble-metric-value" id="live-hr">--</span>
            <span class="ble-metric-label">BPM</span>
        </div>
    </div>

    <div class="ble-live ble-live-secondary">
        <div class="ble-metric">
            <span class="ble-metric-value ble-metric-sm" id="live-speed">--</span>
            <span class="ble-metric-label">KM/H</span>
        </div>
        <div class="ble-metric">
            <span class="ble-metric-value ble-metric-sm" id="live-distance">0.00</span>
            <span class="ble-metric-label">KM</span>
        </div>
    </div>

    <div class="ride-phase-position" id="phase-position"></div>

    <div class="ride-profile-wrap">
        <svg id="workout-profile" class="ride-profile" preserveAspectRatio="none"></svg>
        <div class="ride-profile-cursor" id="profile-cursor"></div>
    </div>

    <div class="ride-progress">
        <div class="ride-progress-bar" id="progress-bar" style="width:0%"></div>
    </div>

    <div class="ride-next" id="next-phase"></div>

    <div class="dj-bar" id="dj-bar">
        <button class="dj-toggle" id="dj-toggle" onclick="toggleDJ()">DJ Mode</button>
        <button class="dj-toggle" id="coach-toggle" onclick="toggleCoach()">AI Coach</button>
    </div>
    <div class="coach-text" id="coach-text"></div>

    <button class="btn btn-stop" style="max-width:200px;margin-top:40px;" onclick="endRide()">End Ride</button>
</div>
@endsection

@push('scripts')
<script src="/js/audio.js"></script>
<script src="/js/bleClient.js"></script>
<script src="/js/workoutPlayer.js"></script>
<script src="/js/dynamicDJ.js"></script>
<script src="/js/aiCoach.js"></script>
<script>
const SESSION_ID = {{ $session->id }};
const PHASES = @json($session->workout ? $session->workout->phases : []);
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

document.addEventListener('DOMContentLoaded', () => {
    BleClient.connect();

    BleClient.onCadence(rpm => {
        document.getElementById('live-cadence').textContent = rpm;
        WorkoutPlayer.recordCadence(rpm);
        updateZone(rpm);
    });

    BleClient.onHR(bpm => {
        document.getElementById('live-hr').textContent = bpm;
        WorkoutPlayer.recordHR(bpm);
    });

    BleClient.onSpeed((kmh, distanceKm) => {
        document.getElementById('live-speed').textContent = kmh.toFixed(1);
        document.getElementById('live-distance').textContent = distanceKm.toFixed(2);
    });

    BleClient.onStatus(connected => {
        const zone = document.getElementById('cadence-zone');
        if (!connected) {
            zone.textContent = '--';
            zone.className = 'ble-zone';
            document.getElementById('live-cadence').textContent = '--';
            document.getElementById('live-hr').textContent = '--';
            document.getElementById('live-speed').textContent = '--';
        }
    });

    if (PHASES.length > 0) {
        buildProfile(PHASES);
        WorkoutPlayer.init(SESSION_ID, PHASES, CSRF);
    }
});

function buildProfile(phases) {
    const svg = document.getElementById('workout-profile');
    const totalSec = phases.reduce((s, p) => s + p.duration_sec, 0);
    const maxRes = Math.max(...phases.map(p => p.resistance), 1);

    const W = 1000;
    const H = 80;
    svg.setAttribute('viewBox', `0 0 ${W} ${H}`);

    const colors = { warmup: '#f59e0b', work: '#ef4444', rest: '#10b981', cooldown: '#2563eb' };
    let x = 0;

    phases.forEach((phase, i) => {
        const w = (phase.duration_sec / totalSec) * W;
        const h = (phase.resistance / maxRes) * (H - 10) + 10;
        const y = H - h;

        const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        rect.setAttribute('x', x);
        rect.setAttribute('y', y);
        rect.setAttribute('width', w);
        rect.setAttribute('height', h);
        rect.setAttribute('fill', colors[phase.type] || '#555');
        rect.setAttribute('opacity', '0.35');
        rect.setAttribute('data-phase', i);
        rect.setAttribute('rx', '2');
        svg.appendChild(rect);

        // Minute markers
        const mins = Math.floor(phase.duration_sec / 60);
        if (mins >= 2) {
            for (let m = 1; m < mins; m++) {
                const mx = x + (m * 60 / phase.duration_sec) * w;
                const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                line.setAttribute('x1', mx);
                line.setAttribute('x2', mx);
                line.setAttribute('y1', y);
                line.setAttribute('y2', H);
                line.setAttribute('stroke', 'rgba(255,255,255,0.08)');
                line.setAttribute('stroke-width', '0.5');
                svg.appendChild(line);
            }
        }

        x += w;
    });

    // Store for cursor updates
    window._profileData = { totalSec, W };
}

function updateProfileCursor(phaseIndex, phaseElapsedSec) {
    if (!window._profileData) return;
    const { totalSec, W } = window._profileData;
    const svg = document.getElementById('workout-profile');
    const cursor = document.getElementById('profile-cursor');

    // Calculate elapsed seconds up to current phase
    let elapsed = 0;
    for (let i = 0; i < phaseIndex; i++) elapsed += PHASES[i].duration_sec;
    elapsed += phaseElapsedSec;

    const pct = (elapsed / totalSec) * 100;
    cursor.style.left = pct + '%';

    // Highlight current phase, dim past
    svg.querySelectorAll('rect').forEach(rect => {
        const pi = parseInt(rect.getAttribute('data-phase'));
        if (pi < phaseIndex) rect.setAttribute('opacity', '0.15');
        else if (pi === phaseIndex) rect.setAttribute('opacity', '0.8');
        else rect.setAttribute('opacity', '0.35');
    });

    // Update position label
    document.getElementById('phase-position').textContent = 'Phase ' + (phaseIndex + 1) + ' of ' + PHASES.length;
}

function updateZone(rpm) {
    const zone = document.getElementById('cadence-zone');
    const target = WorkoutPlayer.getCurrentTarget();
    if (!target) { zone.textContent = '--'; zone.className = 'ble-zone'; return; }

    if (rpm < target.low - 5) {
        zone.textContent = '\u2191 Speed up';
        zone.className = 'ble-zone zone-below';
    } else if (rpm > target.high + 5) {
        zone.textContent = '\u2193 Ease off';
        zone.className = 'ble-zone zone-above';
    } else {
        zone.textContent = '\u2713 On pace';
        zone.className = 'ble-zone zone-on';
    }
}

// --- Resistance control ---
let currentResistance = 0;
let targetResistance = 0;

function setResistanceDisplay(actual, target) {
    currentResistance = actual;
    targetResistance = target;
    document.getElementById('res-actual').textContent = actual;
    const hint = document.getElementById('res-target-hint');
    if (target > 0 && target !== actual) {
        hint.textContent = 'target: ' + target;
        hint.style.color = actual < target ? 'var(--accent-medium)' : actual > target ? 'var(--accent-easy)' : 'var(--text-secondary)';
    } else {
        hint.textContent = '';
    }
}

function adjustResistance(delta) {
    const newVal = Math.max(1, Math.min(100, currentResistance + delta));
    setResistanceDisplay(newVal, targetResistance);
}

// Scroll wheel on the resistance display
(function() {
    const el = document.getElementById('resistance-control');
    if (!el) return;

    // Mouse wheel
    el.addEventListener('wheel', e => {
        e.preventDefault();
        adjustResistance(e.deltaY < 0 ? 1 : -1);
    }, { passive: false });

    // Touch drag (vertical swipe)
    let touchStartY = 0;
    let touchAccum = 0;
    const TOUCH_THRESHOLD = 15; // px per increment

    el.addEventListener('touchstart', e => {
        touchStartY = e.touches[0].clientY;
        touchAccum = 0;
    }, { passive: true });

    el.addEventListener('touchmove', e => {
        e.preventDefault();
        const dy = touchStartY - e.touches[0].clientY;
        touchAccum += dy;
        touchStartY = e.touches[0].clientY;

        while (touchAccum >= TOUCH_THRESHOLD) {
            adjustResistance(1);
            touchAccum -= TOUCH_THRESHOLD;
        }
        while (touchAccum <= -TOUCH_THRESHOLD) {
            adjustResistance(-1);
            touchAccum += TOUCH_THRESHOLD;
        }
    }, { passive: false });
})();

DynamicDJ.onStatus(msg => {
    document.getElementById('dj-status').textContent = msg;
});

AICoach.onStatus(msg => {
    const el = document.getElementById('coach-text');
    el.textContent = msg;
    el.classList.add('visible');
    clearTimeout(el._fadeTimer);
    el._fadeTimer = setTimeout(() => el.classList.remove('visible'), 12000);
});

function toggleDJ() {
    const btn = document.getElementById('dj-toggle');
    if (DynamicDJ.isActive()) {
        DynamicDJ.stop();
        btn.classList.remove('active');
    } else {
        DynamicDJ.start(SESSION_ID, CSRF);
        btn.classList.add('active');
    }
}

function toggleCoach() {
    const btn = document.getElementById('coach-toggle');
    if (AICoach.isActive()) {
        AICoach.stop();
        btn.classList.remove('active');
    } else {
        AICoach.start(SESSION_ID, CSRF);
        btn.classList.add('active');
    }
}

function endRide() {
    if (DynamicDJ.isActive()) DynamicDJ.stop();
    if (AICoach.isActive()) AICoach.stop();
    WorkoutPlayer.abort();
}
</script>
@endpush
