'use strict';

const AICoach = (() => {
    let active = false;
    let csrf = '';
    let checkIntervalId = null;
    let telemetryIntervalId = null;
    let audioCtx = null;
    let lastPhaseIndex = -1;
    let lastPositiveCueTime = 0;
    let lastAnyCueTime = 0;
    let sessionId = null;
    let sessionStartTime = 0;
    let statusCallback = null;

    // Timing
    const CHECK_INTERVAL = 15000;        // Evaluate state every 15s
    const POSITIVE_MIN_GAP = 180000;     // Positive reinforcement at most every 3 min
    const STRUGGLING_MIN_GAP = 45000;    // Motivational cues at most every 45s
    const TELEMETRY_INTERVAL = 5000;     // Log telemetry every 5s
    const TELEMETRY_BATCH_SIZE = 6;      // Flush every 30s (6 × 5s)

    let telemetryBuffer = [];

    function headers() {
        return {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'Accept': 'application/json',
        };
    }

    function start(sessId, csrfToken) {
        sessionId = sessId;
        csrf = csrfToken;
        active = true;
        lastPhaseIndex = -1;
        lastPositiveCueTime = 0;
        lastAnyCueTime = 0;
        sessionStartTime = performance.now();
        telemetryBuffer = [];

        checkIntervalId = setInterval(evaluate, CHECK_INTERVAL);
        telemetryIntervalId = setInterval(recordTelemetry, TELEMETRY_INTERVAL);
        notify('Jocko is watching.');
    }

    function stop() {
        active = false;
        if (checkIntervalId) { clearInterval(checkIntervalId); checkIntervalId = null; }
        if (telemetryIntervalId) { clearInterval(telemetryIntervalId); telemetryIntervalId = null; }
        flushTelemetry();
        notify('Coach off');
    }

    function onStatus(cb) { statusCallback = cb; }
    function notify(msg) { if (statusCallback) statusCallback(msg); }

    // --- Telemetry logging ---

    function recordTelemetry() {
        if (!active || !sessionId) return;

        const cadence = getBLE('getCadence');
        const hr = getBLE('getHR');
        const speed = getBLE('getSpeed');
        const distance = getBLE('getDistance');
        const phaseType = document.getElementById('phase-type')?.textContent?.toLowerCase() || null;
        const resActual = typeof currentResistance !== 'undefined' ? currentResistance : null;
        const resTarget = typeof targetResistance !== 'undefined' ? targetResistance : null;
        const elapsed = Math.round((performance.now() - sessionStartTime) / 1000);

        telemetryBuffer.push({
            elapsed_sec: elapsed,
            cadence_rpm: cadence > 0 ? cadence : null,
            heart_rate_bpm: hr > 0 ? hr : null,
            speed_kmh: speed > 0 ? Math.round(speed * 10) / 10 : null,
            distance_km: distance > 0 ? Math.round(distance * 1000) / 1000 : null,
            resistance_actual: resActual > 0 ? resActual : null,
            resistance_target: resTarget > 0 ? resTarget : null,
            phase_type: phaseType !== 'paused' ? phaseType : null,
        });

        if (telemetryBuffer.length >= TELEMETRY_BATCH_SIZE) {
            flushTelemetry();
        }
    }

    function flushTelemetry() {
        if (!sessionId || telemetryBuffer.length === 0) return;
        const batch = telemetryBuffer.splice(0);

        fetch('/api/workout/' + sessionId + '/telemetry', {
            method: 'POST',
            headers: headers(),
            body: JSON.stringify({ points: batch }),
        }).catch(() => {
            // Put them back on failure
            telemetryBuffer.unshift(...batch);
        });
    }

    // --- Smart coaching evaluation ---

    function evaluate() {
        if (!active) return;

        const now = Date.now();
        const cadence = getBLE('getCadence');
        const hr = getBLE('getHR');
        const target = typeof WorkoutPlayer !== 'undefined' ? WorkoutPlayer.getCurrentTarget() : null;

        if (cadence < 5) return; // Not pedalling

        const trigger = detectTrigger(cadence, hr, target);

        if (trigger === 'on_track') {
            // Only send positive cue every 3+ minutes
            if (now - lastPositiveCueTime < POSITIVE_MIN_GAP) return;
            lastPositiveCueTime = now;
        } else {
            // Struggling/corrective cue — at most every 45s
            if (now - lastAnyCueTime < STRUGGLING_MIN_GAP) return;
        }

        lastAnyCueTime = now;
        requestCue(trigger);
    }

    function detectTrigger(cadence, hr, target) {
        // Heart rate too high — override everything
        if (hr > 170) return 'hr_high';

        if (!target) return 'on_track';

        const midTarget = (target.low + target.high) / 2;

        // Cadence way below target
        if (cadence < target.low - 10) return 'cadence_low';
        if (cadence < target.low - 3) return 'struggling';

        // Cadence way above target
        if (cadence > target.high + 10) return 'cadence_high';

        return 'on_track';
    }

    /**
     * Call from ride when phase changes — immediate cue.
     */
    function onPhaseChange(phaseIndex) {
        if (!active) return;
        if (phaseIndex === lastPhaseIndex) return;
        lastPhaseIndex = phaseIndex;
        setTimeout(() => requestCue('phase_change'), 3000);
    }

    // --- Cue generation ---

    function getRideState(trigger) {
        const target = typeof WorkoutPlayer !== 'undefined' ? WorkoutPlayer.getCurrentTarget() : null;
        const cadence = getBLE('getCadence');
        const hr = getBLE('getHR');
        const speed = getBLE('getSpeed');
        const distance = getBLE('getDistance');
        const resActual = typeof currentResistance !== 'undefined' ? currentResistance : 0;
        const resTarget = typeof targetResistance !== 'undefined' ? targetResistance : 0;

        const phaseType = document.getElementById('phase-type')?.textContent?.toLowerCase() || 'unknown';
        const phaseLabel = document.getElementById('phase-label')?.textContent || '';
        const timerText = document.getElementById('timer')?.textContent || '0:00';

        const parts = timerText.split(':');
        const timeRemaining = parts.length === 2 ? parseInt(parts[0]) * 60 + parseInt(parts[1]) : 0;

        return {
            trigger: trigger,
            phase_type: phaseType,
            phase_label: phaseLabel,
            time_remaining_sec: timeRemaining,
            cadence_rpm: cadence,
            target_rpm_low: target ? target.low : 0,
            target_rpm_high: target ? target.high : 0,
            heart_rate_bpm: hr > 0 ? hr : null,
            speed_kmh: speed > 0 ? speed : null,
            distance_km: distance > 0 ? distance : null,
            resistance_actual: resActual,
            resistance_target: resTarget,
        };
    }

    function requestCue(trigger) {
        if (!active) return;

        const state = getRideState(trigger);

        fetch('/api/coach/cue', {
            method: 'POST',
            headers: headers(),
            body: JSON.stringify(state),
        })
        .then(r => r.json())
        .then(res => {
            if (!res.data) return;
            const { text, audio } = res.data;
            if (text) notify(text);
            if (audio) {
                playAudioBase64(audio);
            } else if (text) {
                fallbackSpeak(text);
            }
        })
        .catch(() => {});
    }

    // --- Audio playback ---

    function playAudioBase64(base64) {
        try {
            const bytes = atob(base64);
            const buffer = new Uint8Array(bytes.length);
            for (let i = 0; i < bytes.length; i++) buffer[i] = bytes.charCodeAt(i);

            if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();

            audioCtx.decodeAudioData(buffer.buffer, decoded => {
                const source = audioCtx.createBufferSource();
                source.buffer = decoded;
                source.connect(audioCtx.destination);
                source.start(0);
            });
        } catch (e) { /* silent fallback */ }
    }

    function fallbackSpeak(text) {
        if ('speechSynthesis' in window) {
            const u = new SpeechSynthesisUtterance(text);
            u.rate = 0.95;
            u.pitch = 0.85;
            speechSynthesis.speak(u);
        }
    }

    // --- Helpers ---

    function getBLE(method) {
        return typeof BleClient !== 'undefined' ? BleClient[method]() : 0;
    }

    function isActive() { return active; }

    return { start, stop, onStatus, onPhaseChange, isActive, flushTelemetry };
})();
