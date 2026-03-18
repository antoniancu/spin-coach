'use strict';

const DynamicDJ = (() => {
    let active = false;
    let sessionId = null;
    let csrf = '';
    let pollId = null;
    let lastTrackUri = null;
    let queuedTrackName = null;
    let statusCallback = null;

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
        lastTrackUri = null;
        queuedTrackName = null;
        pollId = setInterval(tick, 5000);
        notify('DJ active');
    }

    function stop() {
        active = false;
        if (pollId) {
            clearInterval(pollId);
            pollId = null;
        }
        notify('DJ stopped');
    }

    function onStatus(cb) { statusCallback = cb; }

    function notify(msg) {
        if (statusCallback) statusCallback(msg);
    }

    function tick() {
        if (!active) return;

        // Check current track progress
        fetch('/api/spotify/dj/now-playing', { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(res => {
                if (!res.data || !res.data.is_playing) return;

                const track = res.data;
                const remaining = track.duration_ms - track.progress_ms;
                const trackChanged = lastTrackUri && track.uri !== lastTrackUri;
                lastTrackUri = track.uri;

                // Queue next when <30s remaining or track just changed
                if (remaining < 30000 || trackChanged) {
                    queueNext();
                }
            })
            .catch(() => {});
    }

    function queueNext() {
        if (!active) return;

        const cadence = typeof BleClient !== 'undefined' ? BleClient.getCadence() : 0;
        const hr = typeof BleClient !== 'undefined' ? BleClient.getHR() : 0;

        if (cadence < 5) return; // Not pedalling, don't queue

        const deviceId = localStorage.getItem('spotify_device_id');
        const body = {
            session_id: sessionId,
            cadence_rpm: cadence,
            heart_rate_bpm: hr > 0 ? hr : null,
            device_id: deviceId,
        };

        fetch('/api/spotify/dj/next', {
            method: 'POST',
            headers: headers(),
            body: JSON.stringify(body),
        })
        .then(r => r.json())
        .then(res => {
            if (res.data && res.data.queued && res.data.track) {
                queuedTrackName = res.data.track.name;
                const bpm = res.data.target_bpm;
                const tempo = res.data.track.tempo ? Math.round(res.data.track.tempo) : '?';
                notify('Queued: ' + queuedTrackName + ' (' + tempo + ' BPM, target ' + bpm + ')');
            }
        })
        .catch(() => {});
    }

    function isActive() { return active; }

    return { start, stop, onStatus, isActive };
})();
