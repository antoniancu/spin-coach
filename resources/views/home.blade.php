@extends('layouts.app')

@section('title', 'SpinCoach')

@section('content')
<div class="container">
    <div class="nav-bar">
        <span class="logo">SpinCoach</span>
        <div style="display:flex;align-items:center;gap:14px;">
            <a href="/history" class="nav-icon" aria-label="History">&#128336;</a>
            <a href="/settings" class="nav-icon" aria-label="Settings">&#9881;</a>
            <a href="/select-user" class="user-badge" onclick="event.preventDefault(); switchUser();">
                {{ request()->user()->avatar_emoji ?? '' }} {{ request()->user()->name ?? '' }}
            </a>
        </div>
    </div>

    <h1>Let's ride</h1>
    <h2>Pick your intensity and duration</h2>

    <div class="section-label">Intensity</div>
    <div class="intensity-grid">
        <button class="intensity-btn" data-intensity="easy" onclick="pickIntensity('easy')">Easy</button>
        <button class="intensity-btn" data-intensity="medium" onclick="pickIntensity('medium')">Medium</button>
        <button class="intensity-btn" data-intensity="hard" onclick="pickIntensity('hard')">Hard</button>
    </div>

    <div id="duration-section" class="hidden" style="margin-top:24px;">
        <div class="section-label">Duration</div>
        <div class="duration-grid">
            <button class="duration-btn" data-duration="20" onclick="pickDuration(20)">20 min</button>
            <button class="duration-btn" data-duration="30" onclick="pickDuration(30)">30 min</button>
            <button class="duration-btn" data-duration="45" onclick="pickDuration(45)">45 min</button>
        </div>
    </div>

    <div id="workout-preview" class="workout-preview">
        <h3 id="preview-name"></h3>
        <p id="preview-desc"></p>
        <div id="preview-profile" class="preview-profile"></div>
        <p id="preview-phases" style="margin-top:8px;font-size:13px;color:var(--text-secondary);"></p>
    </div>

    <button id="start-btn" class="btn btn-start hidden" onclick="startRide()">Let's SPIN! 🚴</button>
</div>
@endsection

@push('scripts')
<script>
let selectedIntensity = null;
let selectedDuration = null;
let workoutId = null;
const csrf = document.querySelector('meta[name="csrf-token"]').content;

function pickIntensity(intensity) {
    selectedIntensity = intensity;
    document.querySelectorAll('.intensity-btn').forEach(b => b.classList.toggle('selected', b.dataset.intensity === intensity));
    document.getElementById('duration-section').classList.remove('hidden');
    if (selectedDuration) fetchWorkout();
}

function pickDuration(duration) {
    selectedDuration = duration;
    document.querySelectorAll('.duration-btn').forEach(b => b.classList.toggle('selected', parseInt(b.dataset.duration) === duration));
    if (selectedIntensity) fetchWorkout();
}

function fetchWorkout() {
    fetch(`/api/workouts?intensity=${selectedIntensity}&duration=${selectedDuration}`, {
        headers: { 'Accept': 'application/json' },
    })
    .then(r => r.json())
    .then(res => {
        if (res.data) {
            workoutId = res.data.id;
            document.getElementById('preview-name').textContent = res.data.name;
            document.getElementById('preview-desc').textContent = res.data.description || '';
            document.getElementById('preview-phases').textContent = res.data.phase_count + ' phases · ' + res.data.duration_min + ' min';
            document.getElementById('workout-preview').classList.add('visible');
            document.getElementById('start-btn').classList.remove('hidden');
            if (res.data.phases) buildPreviewProfile(res.data.phases, res.data.duration_min);
        }
    });
}

function buildPreviewProfile(phases, totalMin) {
    const container = document.getElementById('preview-profile');
    container.innerHTML = '';

    const totalSec = phases.reduce((s, p) => s + p.duration_sec, 0);
    const maxRes = Math.max(...phases.map(p => p.resistance), 1);
    const colors = { warmup: '#f59e0b', work: '#ef4444', rest: '#10b981', cooldown: '#2563eb' };

    // Build 1-minute bars
    const bars = [];
    let secCursor = 0;

    phases.forEach(phase => {
        const phaseEnd = secCursor + phase.duration_sec;
        while (secCursor < phaseEnd) {
            const barEnd = Math.min(secCursor + 60, phaseEnd);
            const fraction = (barEnd - secCursor) / 60;
            bars.push({
                resistance: phase.resistance,
                type: phase.type,
                fraction: fraction,
                label: phase.label,
            });
            secCursor = barEnd;
        }
    });

    const wrap = document.createElement('div');
    wrap.className = 'preview-bars';

    bars.forEach((bar, i) => {
        const col = document.createElement('div');
        col.className = 'preview-bar-col';
        col.style.flex = bar.fraction;

        const fill = document.createElement('div');
        fill.className = 'preview-bar-fill';
        const heightPct = (bar.resistance / maxRes) * 100;
        fill.style.height = heightPct + '%';
        fill.style.background = colors[bar.type] || '#555';

        const levelLabel = document.createElement('span');
        levelLabel.className = 'preview-bar-level';
        levelLabel.textContent = bar.resistance;

        col.appendChild(fill);

        // Show level label at phase transitions or every few bars
        if (i === 0 || bars[i - 1].resistance !== bar.resistance) {
            fill.appendChild(levelLabel);
        }

        wrap.appendChild(col);
    });

    // Minute markers below
    const axis = document.createElement('div');
    axis.className = 'preview-axis';
    for (let m = 0; m <= totalMin; m += 5) {
        const tick = document.createElement('span');
        tick.className = 'preview-tick';
        tick.style.left = (m / totalMin * 100) + '%';
        tick.textContent = m + 'm';
        axis.appendChild(tick);
    }

    container.appendChild(wrap);
    container.appendChild(axis);
}

function startRide() {
    fetch('/api/workout/start', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'Accept': 'application/json',
        },
        body: JSON.stringify({
            workout_id: workoutId,
            intensity: selectedIntensity,
            duration_planned_min: selectedDuration,
        }),
    })
    .then(r => r.json())
    .then(res => {
        if (res.data && res.data.session_id) {
            window.location.href = '/ride/' + res.data.session_id;
        }
    });
}

function switchUser() {
    fetch('/users/deselect', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
    }).then(() => window.location.href = '/select-user');
}
</script>
@endpush
