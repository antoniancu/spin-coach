'use strict';

const SpotifyUI = (() => {
    let csrf = '';
    let pollInterval = null;

    function init(csrfToken) {
        csrf = csrfToken;
        checkStatus();
    }

    function headers() {
        return {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'Accept': 'application/json',
        };
    }

    function checkStatus() {
        fetch('/api/spotify/status', { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(res => {
                const connected = res.data && res.data.connected;
                document.getElementById('spotify-status-text').textContent = connected ? 'Connected' : 'Not connected';
                document.getElementById('spotify-connect-btn').style.display = connected ? 'none' : 'inline-flex';

                if (connected) {
                    loadDevices();
                    loadNowPlaying();
                }
            })
            .catch(() => {
                document.getElementById('spotify-status-text').textContent = 'Unable to check status';
            });
    }

    function loadDevices() {
        fetch('/api/spotify/devices', { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(res => {
                if (!res.data || !res.data.devices || !res.data.devices.length) return;

                const section = document.getElementById('spotify-devices');
                section.classList.remove('hidden');
                const list = document.getElementById('device-list');
                list.innerHTML = '';

                res.data.devices.forEach(device => {
                    const div = document.createElement('div');
                    div.style.cssText = 'padding:12px 16px;background:var(--bg-card);border-radius:8px;display:flex;justify-content:space-between;align-items:center;cursor:pointer;';
                    if (device.is_active) {
                        div.style.borderLeft = '3px solid var(--accent-easy)';
                    }
                    div.innerHTML = `<span>${device.name} <small style="color:var(--text-secondary);">(${device.type})</small></span>`;
                    div.addEventListener('click', () => {
                        localStorage.setItem('spotify_device_id', device.id);
                        loadDevices();
                    });
                    list.appendChild(div);
                });
            })
            .catch(() => {});
    }

    function loadNowPlaying() {
        fetch('/api/spotify/now-playing', { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(res => {
                const section = document.getElementById('spotify-now-playing');
                if (res.data && res.data.track && res.data.is_playing) {
                    section.classList.remove('hidden');
                    document.getElementById('np-track').textContent = res.data.track.name;
                    document.getElementById('np-artist').textContent = res.data.track.artist;
                } else {
                    section.classList.add('hidden');
                }
            })
            .catch(() => {});
    }

    function play(contextUri, deviceId) {
        const body = {};
        if (contextUri) body.context_uri = contextUri;
        if (deviceId) body.device_id = deviceId;
        body.volume_percent = 80;

        return fetch('/api/spotify/play', {
            method: 'POST',
            headers: headers(),
            body: JSON.stringify(body),
        }).then(r => r.json());
    }

    function pause() {
        const deviceId = localStorage.getItem('spotify_device_id');
        const body = deviceId ? { device_id: deviceId } : {};

        return fetch('/api/spotify/pause', {
            method: 'POST',
            headers: headers(),
            body: JSON.stringify(body),
        })
        .then(r => r.json())
        .then(() => loadNowPlaying());
    }

    function next() {
        const deviceId = localStorage.getItem('spotify_device_id');
        const body = deviceId ? { device_id: deviceId } : {};

        return fetch('/api/spotify/next', {
            method: 'POST',
            headers: headers(),
            body: JSON.stringify(body),
        })
        .then(r => r.json())
        .then(() => setTimeout(loadNowPlaying, 1000));
    }

    function setVolume(percent) {
        return fetch('/api/spotify/volume', {
            method: 'PUT',
            headers: headers(),
            body: JSON.stringify({ volume_percent: percent }),
        }).then(r => r.json());
    }

    function startPolling() {
        if (pollInterval) return;
        pollInterval = setInterval(loadNowPlaying, 10000);
    }

    function stopPolling() {
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
        }
    }

    return { init, play, pause, next, setVolume, startPolling, stopPolling, loadNowPlaying };
})();
