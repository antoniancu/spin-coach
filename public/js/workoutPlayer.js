'use strict';

const WorkoutPlayer = (() => {
    let sessionId = null;
    let phases = [];
    let csrf = '';
    let currentPhaseIndex = 0;
    let phaseStartTime = 0;
    let rafId = null;
    let warningFired = false;
    let sessionStartTime = 0;
    let wakeLock = null;
    let aborted = false;

    // DOM elements
    const els = {};

    function init(id, phaseList, csrfToken) {
        sessionId = id;
        phases = phaseList;
        csrf = csrfToken;

        els.phaseType = document.getElementById('phase-type');
        els.phaseLabel = document.getElementById('phase-label');
        els.timer = document.getElementById('timer');
        els.cadence = document.getElementById('cadence-target');
        els.resistance = document.getElementById('resistance-target');
        els.progressBar = document.getElementById('progress-bar');
        els.nextPhase = document.getElementById('next-phase');

        sessionStartTime = performance.now();
        acquireWakeLock();
        startPhase(0);
    }

    function startPhase(index) {
        if (index >= phases.length) {
            finishSession(true);
            return;
        }

        currentPhaseIndex = index;
        warningFired = false;
        phaseStartTime = performance.now();

        const phase = phases[index];
        els.phaseType.textContent = phase.type.toUpperCase();
        els.phaseLabel.textContent = phase.label;
        els.cadence.textContent = phase.rpm_low + '–' + phase.rpm_high + ' RPM';
        els.resistance.textContent = 'Resistance ' + phase.resistance;

        // Color the timer based on phase type
        const colors = { warmup: '#f59e0b', work: '#ef4444', rest: '#10b981', cooldown: '#2563eb' };
        els.timer.style.color = colors[phase.type] || '#e0e0e0';

        // Show next phase
        if (index + 1 < phases.length) {
            const next = phases[index + 1];
            els.nextPhase.textContent = 'Up next: ' + next.label;
        } else {
            els.nextPhase.textContent = 'Final phase';
        }

        // Audio cue
        if (index > 0) Audio.transition();
        Audio.speak(phase.audio_cue);

        tick(performance.now());
    }

    function tick(timestamp) {
        if (aborted) return;

        const elapsed = timestamp - phaseStartTime;
        const phase = phases[currentPhaseIndex];
        const totalMs = phase.duration_sec * 1000;
        const remaining = totalMs - elapsed;

        // Update timer
        const secLeft = Math.max(0, Math.ceil(remaining / 1000));
        const min = Math.floor(secLeft / 60);
        const sec = secLeft % 60;
        els.timer.textContent = String(min).padStart(2, '0') + ':' + String(sec).padStart(2, '0');

        // Update progress bar
        const progress = Math.min(100, (elapsed / totalMs) * 100);
        els.progressBar.style.width = progress + '%';

        // 5-second warning
        if (remaining <= 5000 && remaining > 0 && !warningFired) {
            Audio.warning();
            warningFired = true;
        }

        if (remaining <= 0) {
            completePhase();
        } else {
            rafId = requestAnimationFrame(tick);
        }
    }

    function completePhase() {
        const phase = phases[currentPhaseIndex];
        const actualMs = performance.now() - phaseStartTime;

        // Log interval to server
        fetch('/api/workout/' + sessionId + '/interval', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                sequence: currentPhaseIndex,
                phase_type: phase.type,
                target_rpm_low: phase.rpm_low,
                target_rpm_high: phase.rpm_high,
                target_resistance: phase.resistance,
                duration_sec: phase.duration_sec,
                actual_duration_sec: Math.round(actualMs / 1000),
                avg_cadence_rpm: null,
                avg_heart_rate_bpm: null,
            }),
        }).catch(() => {});

        startPhase(currentPhaseIndex + 1);
    }

    function finishSession(completed) {
        aborted = true;
        if (rafId) cancelAnimationFrame(rafId);

        const totalSec = Math.round((performance.now() - sessionStartTime) / 1000);

        Audio.complete();
        releaseWakeLock();

        els.phaseType.textContent = completed ? 'FINISHED' : 'ENDED';
        els.phaseLabel.textContent = completed ? 'Great ride!' : 'Ride ended';
        els.timer.textContent = formatTime(totalSec);
        els.timer.style.color = '#10b981';
        els.cadence.textContent = '';
        els.resistance.textContent = '';
        els.nextPhase.textContent = '';
        els.progressBar.style.width = '100%';

        fetch('/api/workout/' + sessionId + '/finish', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                completed: completed,
                duration_actual_sec: totalSec,
            }),
        })
        .then(r => r.json())
        .then(res => {
            if (res.data && res.data.summary_url) {
                setTimeout(() => window.location.href = res.data.summary_url, 3000);
            }
        })
        .catch(() => {
            setTimeout(() => window.location.href = '/home', 3000);
        });
    }

    function abort() {
        finishSession(false);
    }

    function formatTime(totalSec) {
        const min = Math.floor(totalSec / 60);
        const sec = totalSec % 60;
        return String(min).padStart(2, '0') + ':' + String(sec).padStart(2, '0');
    }

    async function acquireWakeLock() {
        try {
            if ('wakeLock' in navigator) {
                wakeLock = await navigator.wakeLock.request('screen');
            }
        } catch (e) {
            // Wake lock not available
        }
    }

    function releaseWakeLock() {
        if (wakeLock) {
            wakeLock.release();
            wakeLock = null;
        }
    }

    // Re-acquire wake lock on visibility change
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && !aborted) {
            acquireWakeLock();
        }
    });

    return { init, abort };
})();
