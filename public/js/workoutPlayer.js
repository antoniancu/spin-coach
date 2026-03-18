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
    let cadenceSamples = [];
    let hrSamples = [];
    let paused = false;
    let pauseStartTime = 0;
    let totalPausedMs = 0;
    let waitingForFirstPedal = true;

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
        cadenceSamples = [];
        hrSamples = [];
        totalPausedMs = 0;

        // First phase waits for pedalling; subsequent phases start immediately
        if (index === 0) {
            waitingForFirstPedal = true;
            paused = true;
            pauseStartTime = performance.now();
        } else {
            waitingForFirstPedal = false;
            paused = false;
            pauseStartTime = 0;
        }

        const phase = phases[index];
        els.phaseType.textContent = waitingForFirstPedal ? 'START PEDALLING' : phase.type.toUpperCase();
        els.timer.style.opacity = waitingForFirstPedal ? '0.4' : '1';
        els.phaseLabel.textContent = phase.label;
        els.cadence.textContent = phase.rpm_low + '–' + phase.rpm_high + ' RPM';
        // Update resistance control if available, otherwise fallback to text
        if (typeof setResistanceDisplay === 'function') {
            setResistanceDisplay(phase.resistance, phase.resistance);
        }

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

        if (paused) {
            rafId = requestAnimationFrame(tick);
            return;
        }

        const elapsed = timestamp - phaseStartTime - totalPausedMs;
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

        // Update workout profile cursor
        if (typeof updateProfileCursor === 'function') {
            updateProfileCursor(currentPhaseIndex, Math.floor(elapsed / 1000));
        }

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
                avg_cadence_rpm: cadenceSamples.length ? Math.round(cadenceSamples.reduce((a, b) => a + b, 0) / cadenceSamples.length) : null,
                avg_heart_rate_bpm: hrSamples.length ? Math.round(hrSamples.reduce((a, b) => a + b, 0) / hrSamples.length) : null,
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

        els.timer.style.opacity = '1';

        // Send finish request first, then show feedback
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
            const summaryUrl = (res.data && res.data.summary_url) || '/home';
            showFeedbackScreen(totalSec, completed, summaryUrl);
        })
        .catch(() => {
            showFeedbackScreen(totalSec, completed, '/home');
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

    function showFeedbackScreen(totalSec, completed, summaryUrl) {
        const display = document.getElementById('ride-display');
        display.innerHTML = '';
        display.className = 'ride-display ride-feedback';

        const title = document.createElement('div');
        title.className = 'ride-phase-label';
        title.textContent = completed ? 'FINISHED' : 'ENDED';
        display.appendChild(title);

        const subtitle = document.createElement('div');
        subtitle.className = 'ride-phase-name';
        subtitle.textContent = completed ? 'Great ride!' : 'Ride ended';
        display.appendChild(subtitle);

        const timer = document.createElement('div');
        timer.className = 'ride-timer';
        timer.style.color = '#10b981';
        timer.textContent = formatTime(totalSec);
        display.appendChild(timer);

        const prompt = document.createElement('div');
        prompt.className = 'feedback-prompt';
        prompt.textContent = 'How did that feel?';
        display.appendChild(prompt);

        const options = [
            { value: 1, emoji: '😴', label: 'Too easy' },
            { value: 2, emoji: '😌', label: 'Easy' },
            { value: 3, emoji: '💪', label: 'Just right' },
            { value: 4, emoji: '😤', label: 'Hard' },
            { value: 5, emoji: '🥵', label: 'Too hard' },
        ];

        const grid = document.createElement('div');
        grid.className = 'feedback-grid';

        options.forEach(opt => {
            const btn = document.createElement('button');
            btn.className = 'feedback-btn';
            btn.innerHTML = '<span class="feedback-emoji">' + opt.emoji + '</span><span class="feedback-label">' + opt.label + '</span>';
            btn.onclick = () => submitFeedback(opt.value, summaryUrl);
            grid.appendChild(btn);
        });

        display.appendChild(grid);

        const skip = document.createElement('button');
        skip.className = 'feedback-skip';
        skip.textContent = 'Skip';
        skip.onclick = () => { window.location.href = summaryUrl; };
        display.appendChild(skip);
    }

    function submitFeedback(effort, summaryUrl) {
        fetch('/api/workout/' + sessionId + '/finish', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
            },
            body: JSON.stringify({ perceived_effort: effort }),
        })
        .then(() => { window.location.href = summaryUrl; })
        .catch(() => { window.location.href = summaryUrl; });
    }

    function recordCadence(rpm) {
        cadenceSamples.push(rpm);

        if (rpm >= 5 && paused && !aborted) {
            // Resume from pause (or first pedal)
            totalPausedMs += performance.now() - pauseStartTime;
            paused = false;
            waitingForFirstPedal = false;
            els.phaseType.textContent = phases[currentPhaseIndex].type.toUpperCase();
            els.timer.style.opacity = '1';
        } else if (rpm <= 0 && !paused && !aborted) {
            // Stopped pedalling — pause
            paused = true;
            pauseStartTime = performance.now();
            els.phaseType.textContent = 'PAUSED';
            els.timer.style.opacity = '0.4';
        }
    }
    function recordHR(bpm) { hrSamples.push(bpm); }
    function getCurrentTarget() {
        if (aborted || currentPhaseIndex >= phases.length) return null;
        const p = phases[currentPhaseIndex];
        return { low: p.rpm_low, high: p.rpm_high };
    }
    function isPaused() { return paused; }

    return { init, abort, recordCadence, recordHR, getCurrentTarget, isPaused };
})();
