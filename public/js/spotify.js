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

    let miniPollInterval = null;
    let isPlaying = false;

    function initMiniPlayer(csrfToken) {
        csrf = csrfToken;
        fetch('/api/spotify/status', { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(res => {
                if (res.data && res.data.connected) {
                    refreshMiniPlayer();
                    miniPollInterval = setInterval(refreshMiniPlayer, 10000);
                }
            })
            .catch(() => {});
    }

    function refreshMiniPlayer() {
        fetch('/api/spotify/now-playing', { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(res => {
                const bar = document.getElementById('spotify-bar');
                if (!bar) return;

                bar.classList.remove('hidden');

                const art = document.getElementById('sp-bar-art');
                if (res.data && res.data.track) {
                    document.getElementById('sp-bar-track').textContent = res.data.track.name;
                    document.getElementById('sp-bar-artist').textContent = res.data.track.artist;
                    if (art && res.data.track.album_art_url) {
                        art.src = res.data.track.album_art_url;
                        art.style.display = 'block';
                    }
                    isPlaying = res.data.is_playing;
                } else {
                    document.getElementById('sp-bar-track').textContent = 'Nothing playing';
                    document.getElementById('sp-bar-artist').textContent = '';
                    if (art) { art.style.display = 'none'; }
                    isPlaying = false;
                }

                updatePlayPauseBtn();
                updateEQ();
            })
            .catch(() => {});
    }

    function updateEQ() {
        const eq = document.getElementById('sp-eq');
        if (!eq) return;
        eq.classList.toggle('playing', isPlaying);
    }

    function updatePlayPauseBtn() {
        const btn = document.getElementById('sp-bar-playpause');
        if (!btn) return;
        btn.innerHTML = isPlaying ? '&#9646;&#9646;' : '&#9654;';
        btn.onclick = isPlaying
            ? () => pause().then(refreshMiniPlayer)
            : () => play(null, localStorage.getItem('spotify_device_id')).then(refreshMiniPlayer);
    }

    function toggleDevicePopover() {
        const popover = document.getElementById('sp-device-popover');
        if (!popover) return;

        if (popover.classList.contains('hidden')) {
            popover.classList.remove('hidden');
            loadDevicePopover();
            // Close on outside click
            setTimeout(() => {
                document.addEventListener('click', closePopoverOutside);
            }, 0);
        } else {
            closePopover();
        }
    }

    function closePopover() {
        const popover = document.getElementById('sp-device-popover');
        if (popover) popover.classList.add('hidden');
        document.removeEventListener('click', closePopoverOutside);
    }

    function closePopoverOutside(e) {
        const wrap = document.querySelector('.sp-device-wrap');
        if (wrap && !wrap.contains(e.target)) {
            closePopover();
        }
    }

    function loadDevicePopover() {
        const list = document.getElementById('sp-device-popover-list');
        if (!list) return;
        list.innerHTML = '<div style="padding:8px;color:var(--text-secondary);font-size:13px;">Loading...</div>';

        fetch('/api/spotify/devices', { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(res => {
                list.innerHTML = '';
                const devices = (res.data && res.data.devices) || [];
                if (!devices.length) {
                    list.innerHTML = '<div style="padding:8px;color:var(--text-secondary);font-size:13px;">No devices found</div>';
                    return;
                }
                const savedId = localStorage.getItem('spotify_device_id');
                devices.forEach(device => {
                    const row = document.createElement('button');
                    row.className = 'sp-device-row' + (device.is_active ? ' active' : '') + (device.id === savedId ? ' selected' : '');
                    row.innerHTML = '<span>' + device.name + '</span><small>' + device.type + '</small>';
                    row.onclick = () => {
                        localStorage.setItem('spotify_device_id', device.id);
                        // Transfer playback to this device
                        if (isPlaying) {
                            play(null, device.id).then(refreshMiniPlayer);
                        }
                        closePopover();
                    };
                    list.appendChild(row);
                });
            })
            .catch(() => {
                list.innerHTML = '<div style="padding:8px;color:var(--accent-hard);font-size:13px;">Failed to load devices</div>';
            });
    }

    return { init, initMiniPlayer, refreshMiniPlayer, toggleDevicePopover, play, pause, next, setVolume, startPolling, stopPolling, loadNowPlaying };
})();
