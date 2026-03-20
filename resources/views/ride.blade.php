@extends('layouts.app')

@section('title', 'Ride — SpinCoach')

@section('content')
<div class="ride-display" id="ride-display">
    <a href="/settings?back=/ride/{{ $session->id }}" class="ride-settings-link" aria-label="Settings">&#9881;</a>
    <div class="ride-phase-label" id="phase-type">LOADING</div>
    <div class="ride-phase-name" id="phase-label">Preparing...</div>
    <div class="ride-timer" id="timer">--:--</div>
    <div class="coach-text" id="coach-text"></div>

    <div class="ble-live">
        <div class="ble-metric">
            <span class="ble-metric-value" id="live-cadence">--</span>
            <span class="ble-metric-label">RPM</span>
            <span class="ble-metric-target" id="cadence-target"></span>
        </div>
        <div class="ble-zone" id="cadence-zone">--</div>
        <div class="ble-metric">
            <span class="ble-metric-value" id="live-hr">--</span>
            <span class="ble-metric-label">BPM</span>
        </div>
    </div>

    <div class="resistance-control" id="resistance-control">
        <button class="res-btn res-minus" onclick="adjustResistance(-1)" aria-label="Decrease">−</button>
        <div class="res-display" id="resistance-display">
            <div class="res-inline"><span class="res-actual" id="res-actual">--</span><span class="res-label">/100</span></div>
            <span class="res-target" id="res-target-hint"></span>
        </div>
        <button class="res-btn res-plus" onclick="adjustResistance(1)" aria-label="Increase">+</button>
    </div>

    <div class="ble-live ble-live-secondary" style="margin-top:12px;">
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
        <div id="workout-profile" class="preview-bars" style="height:80px;"></div>
        <svg id="ride-overlay" class="ride-overlay" preserveAspectRatio="none"></svg>
        <div class="ride-profile-cursor" id="profile-cursor"></div>
        <div id="ride-profile-axis" class="preview-axis"></div>
    </div>

    <div class="ride-stats-row">
        <span class="ride-stat" id="stat-calories">0 <small>cal</small></span>
    </div>

    <div class="ride-progress">
        <div class="ride-progress-bar" id="progress-bar" style="width:0%"></div>
    </div>

    <div class="ride-next" id="next-phase"></div>

    <div class="dj-bar" id="dj-bar">
        <button class="dj-toggle" id="dj-toggle" onclick="toggleDJ()">DJ</button>
        <button class="dj-toggle" id="coach-toggle" onclick="toggleCoach()">Coach</button>
    </div>

    <button class="btn btn-stop" style="max-width:200px;margin-top:40px;margin-bottom:100px;" onclick="endRide()">End Ride</button>
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
        recordOverlayPoint();
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

function intensityColor(resistance) {
    if (resistance <= 10) return '#3b82f6';  // blue — recovery
    if (resistance <= 15) return '#f59e0b';  // yellow — easy
    if (resistance <= 20) return '#f97316';  // orange — moderate
    return '#ef4444';                         // red — hard (20+)
}

function buildProfile(phases) {
    const container = document.getElementById('workout-profile');
    container.innerHTML = '';

    const totalSec = phases.reduce((s, p) => s + p.duration_sec, 0);
    const maxRes = 50; // fixed scale — C6 practical max

    // Build 1-minute bars (same approach as home preview)
    const bars = [];
    let secCursor = 0;
    let phaseIdx = 0;

    phases.forEach((phase, pi) => {
        const phaseEnd = secCursor + phase.duration_sec;
        while (secCursor < phaseEnd) {
            const barEnd = Math.min(secCursor + 60, phaseEnd);
            bars.push({
                resistance: phase.resistance,
                rpm: Math.round((phase.rpm_low + phase.rpm_high) / 2),
                type: phase.type,
                fraction: (barEnd - secCursor) / 60,
                phaseIndex: pi,
            });
            secCursor = barEnd;
        }
    });

    bars.forEach((bar, i) => {
        const col = document.createElement('div');
        col.className = 'preview-bar-col';
        col.style.flex = bar.fraction;
        col.dataset.phase = bar.phaseIndex;

        const fill = document.createElement('div');
        fill.className = 'preview-bar-fill';
        // Height = tempo (RPM), color = intensity zone (resistance)
        fill.style.height = Math.max(8, (bar.rpm / 130) * 100) + '%';
        fill.style.background = intensityColor(bar.resistance);

        // Level label at resistance transitions: intensity/tempo
        if (i === 0 || bars[i - 1].resistance !== bar.resistance || bars[i - 1].rpm !== bar.rpm) {
            const lbl = document.createElement('span');
            lbl.className = 'preview-bar-level';
            lbl.textContent = bar.resistance + '/' + bar.rpm;
            fill.appendChild(lbl);
        }

        col.appendChild(fill);
        container.appendChild(col);
    });

    // Time axis
    const actualMin = totalSec / 60;
    const step = actualMin <= 25 ? 5 : 10;
    const axis = document.getElementById('ride-profile-axis');
    axis.innerHTML = '';
    for (let m = 0; m <= actualMin; m += step) {
        const tick = document.createElement('span');
        tick.className = 'preview-tick';
        tick.style.left = (m / actualMin * 100) + '%';
        tick.textContent = m + 'm';
        axis.appendChild(tick);
    }

    window._profileData = { totalSec };
}

function updateProfileCursor(phaseIndex, phaseElapsedSec) {
    if (!window._profileData) return;
    const { totalSec } = window._profileData;
    const container = document.getElementById('workout-profile');
    const cursor = document.getElementById('profile-cursor');

    // Calculate elapsed seconds
    let elapsed = 0;
    for (let i = 0; i < phaseIndex; i++) elapsed += PHASES[i].duration_sec;
    elapsed += phaseElapsedSec;

    cursor.style.left = (elapsed / totalSec * 100) + '%';

    // Highlight current phase, dim past
    container.querySelectorAll('.preview-bar-col').forEach(col => {
        const pi = parseInt(col.dataset.phase);
        const fill = col.querySelector('.preview-bar-fill');
        if (pi < phaseIndex) fill.style.opacity = '0.2';
        else if (pi === phaseIndex) fill.style.opacity = '0.9';
        else fill.style.opacity = '0.6';
    });

    document.getElementById('phase-position').textContent = 'Phase ' + (phaseIndex + 1) + ' of ' + PHASES.length;

    // Update ride overlay (RPM line + actual resistance bars)
    updateRideOverlay(elapsed, totalSec);
}

// --- Ride overlay: RPM line + actual resistance bars ---
const rideHistory = []; // { elapsed, cadence, resActual, resTarget }
let lastOverlayUpdate = 0;

function recordOverlayPoint() {
    if (!window._profileData) return;
    const { totalSec } = window._profileData;
    const cadence = typeof BleClient !== 'undefined' ? BleClient.getCadence() : 0;
    const resA = typeof currentResistance !== 'undefined' ? currentResistance : 0;
    const resT = typeof targetResistance !== 'undefined' ? targetResistance : 0;

    let elapsed = 0;
    const pi = WorkoutPlayer.getPhaseIndex();
    for (let i = 0; i < pi && i < PHASES.length; i++) elapsed += PHASES[i].duration_sec;
    // Approximate current phase elapsed from cursor position
    const cursor = document.getElementById('profile-cursor');
    const pct = parseFloat(cursor?.style.left) || 0;
    elapsed = (pct / 100) * totalSec;

    const hr = typeof BleClient !== 'undefined' ? BleClient.getHR() : 0;
    rideHistory.push({ elapsed, cadence, hr, resActual: resA, resTarget: resT });
}

function updateRideOverlay(elapsed, totalSec) {
    const svg = document.getElementById('ride-overlay');
    if (!svg || rideHistory.length < 2) return;

    const W = svg.clientWidth || 400;
    const H = 80;
    svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);

    // Clear previous
    svg.innerHTML = '';

    const maxRPM = 130;
    const maxRes = 50;

    // Draw actual resistance bars (behind RPM line)
    let prevX = -1;
    const barWidth = Math.max(2, W / (totalSec / 5)); // ~1 bar per 5s of data

    rideHistory.forEach(pt => {
        const x = (pt.elapsed / totalSec) * W;
        if (x - prevX < barWidth * 0.8) return; // skip if too close
        prevX = x;

        const h = (pt.resActual / maxRes) * H;
        const y = H - h;

        // Color based on deviation from target
        let color;
        if (pt.resTarget <= 0) color = 'rgba(160,160,160,0.3)';
        else if (pt.resActual < pt.resTarget - 3) color = 'rgba(239,68,68,0.4)'; // red — below
        else if (pt.resActual > pt.resTarget + 3) color = 'rgba(245,158,11,0.4)'; // yellow — above
        else color = 'rgba(16,185,129,0.4)'; // green — on target

        const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        rect.setAttribute('x', x - barWidth / 2);
        rect.setAttribute('y', y);
        rect.setAttribute('width', barWidth);
        rect.setAttribute('height', h);
        rect.setAttribute('fill', color);
        rect.setAttribute('rx', '1');
        svg.appendChild(rect);
    });

    // Draw RPM polyline
    let points = '';
    rideHistory.forEach(pt => {
        const x = (pt.elapsed / totalSec) * W;
        const y = H - (Math.min(pt.cadence, maxRPM) / maxRPM) * (H - 4) - 2;
        points += x.toFixed(1) + ',' + y.toFixed(1) + ' ';
    });

    if (points) {
        const line = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
        line.setAttribute('points', points.trim());
        line.setAttribute('fill', 'none');
        line.setAttribute('stroke', '#fff');
        line.setAttribute('stroke-width', '1.5');
        line.setAttribute('stroke-opacity', '0.7');
        line.setAttribute('stroke-linejoin', 'round');
        svg.appendChild(line);

        // Draw target RPM range as a subtle band
        let targetPoints = '';
        let targetPointsBottom = '';
        const target = WorkoutPlayer.getCurrentTarget();
        if (target) {
            const yLow = H - (target.low / maxRPM) * (H - 4) - 2;
            const yHigh = H - (target.high / maxRPM) * (H - 4) - 2;
            // Only draw band for the area up to current elapsed
            const xNow = (rideHistory[rideHistory.length - 1].elapsed / totalSec) * W;
            const band = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
            band.setAttribute('x', '0');
            band.setAttribute('y', yHigh);
            band.setAttribute('width', xNow);
            band.setAttribute('height', yLow - yHigh);
            band.setAttribute('fill', 'rgba(124,58,237,0.1)');
            svg.insertBefore(band, svg.firstChild);
        }
    }

    // Draw HR polyline (red)
    const maxHR = 200;
    let hrPoints = '';
    rideHistory.forEach(pt => {
        if (pt.hr > 0) {
            const x = (pt.elapsed / totalSec) * W;
            const y = H - (Math.min(pt.hr, maxHR) / maxHR) * (H - 4) - 2;
            hrPoints += x.toFixed(1) + ',' + y.toFixed(1) + ' ';
        }
    });

    if (hrPoints) {
        const hrLine = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
        hrLine.setAttribute('points', hrPoints.trim());
        hrLine.setAttribute('fill', 'none');
        hrLine.setAttribute('stroke', '#ef4444');
        hrLine.setAttribute('stroke-width', '1.5');
        hrLine.setAttribute('stroke-opacity', '0.6');
        hrLine.setAttribute('stroke-linejoin', 'round');
        svg.appendChild(hrLine);
    }
}

function updateCalorieDisplay(cal) {
    const el = document.getElementById('stat-calories');
    if (el) el.innerHTML = cal + ' <small>cal</small>';
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
