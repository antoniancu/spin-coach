@extends('layouts.app')

@section('title', 'Settings — SpinCoach')

@section('content')
<div class="container">
    <div class="nav-bar">
        <a href="{{ request('back', '/home') }}" class="logo">&larr; Back</a>
        <a href="/select-user" class="user-badge" onclick="event.preventDefault(); switchUser();">
            {{ request()->user()->avatar_emoji }} {{ request()->user()->name }}
        </a>
    </div>

    <h1>Settings</h1>

    @if(session('success'))
    <div style="padding:12px 16px;background:rgba(16,185,129,0.15);border:1px solid var(--accent-easy);border-radius:var(--radius);margin-bottom:20px;color:var(--accent-easy);">
        {{ session('success') }}
    </div>
    @endif
    @if(session('error'))
    <div style="padding:12px 16px;background:rgba(239,68,68,0.15);border:1px solid var(--accent-hard);border-radius:var(--radius);margin-bottom:20px;color:var(--accent-hard);">
        {{ session('error') }}
    </div>
    @endif

    {{-- Spotify Section --}}
    <div id="spotify-section" style="margin-top:24px;">
        <div class="section-label">Spotify</div>
        <div id="spotify-status" style="padding:16px;background:var(--bg-card);border-radius:var(--radius);margin-bottom:12px;">
            <div id="spotify-connection" style="display:flex;justify-content:space-between;align-items:center;">
                <span id="spotify-status-text">Checking...</span>
                <a href="/spotify/connect" id="spotify-connect-btn" class="btn btn-primary" style="width:auto;padding:10px 20px;font-size:14px;display:none;">Connect Spotify</a>
            </div>
        </div>

        {{-- Devices --}}
        <div id="spotify-devices" class="hidden" style="margin-bottom:12px;">
            <div class="section-label" style="margin-top:16px;">Playback Device</div>
            <div id="device-list" style="display:flex;flex-direction:column;gap:8px;"></div>
        </div>

        {{-- Now Playing --}}
        <div id="spotify-now-playing" class="hidden" style="padding:16px;background:var(--bg-card);border-radius:var(--radius);">
            <div style="font-size:13px;color:var(--text-secondary);margin-bottom:8px;">Now Playing</div>
            <div id="np-track" style="font-weight:600;"></div>
            <div id="np-artist" style="font-size:14px;color:var(--text-secondary);"></div>
            <div style="display:flex;gap:12px;margin-top:12px;">
                <button class="btn btn-primary" style="width:auto;padding:8px 16px;font-size:13px;" onclick="SpotifyUI.pause()">Pause</button>
                <button class="btn btn-primary" style="width:auto;padding:8px 16px;font-size:13px;" onclick="SpotifyUI.next()">Skip</button>
            </div>
        </div>
    </div>

    {{-- BLE Bridge Status --}}
    <div style="margin-top:32px;">
        <div class="section-label">BLE Bridge</div>
        <div style="padding:16px;background:var(--bg-card);border-radius:var(--radius);font-size:14px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                <span>WebSocket</span>
                <code style="color:var(--text-secondary);">{{ config('services.ble_bridge.ws_url') }}</code>
            </div>
            <div id="ble-status" style="display:flex;align-items:center;gap:8px;">
                <span id="ble-dot" style="width:8px;height:8px;border-radius:50%;background:var(--text-secondary);"></span>
                <span id="ble-status-text">Connecting...</span>
                <button id="ble-retry-btn" class="hidden" onclick="bleRetry()" style="margin-left:auto;padding:6px 14px;font-size:13px;background:var(--accent-purple);color:#fff;border:none;border-radius:var(--radius);cursor:pointer;">Retry</button>
            </div>
            <div id="ble-live-data" class="hidden" style="margin-top:12px;display:flex;gap:24px;">
                <div><span style="color:var(--text-secondary);font-size:12px;">Cadence</span><br><strong id="ble-settings-cadence">--</strong> RPM</div>
                <div><span style="color:var(--text-secondary);font-size:12px;">Heart Rate</span><br><strong id="ble-settings-hr">--</strong> BPM</div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="/js/bleClient.js"></script>
<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;
SpotifyUI.init(csrf);

BleClient.connect();
BleClient.onStatus(connected => {
    document.getElementById('ble-dot').style.background = connected ? 'var(--accent-easy)' : 'var(--accent-hard)';
    document.getElementById('ble-status-text').textContent = connected ? 'Connected to IC Bike' : 'Not connected';
    document.getElementById('ble-retry-btn').classList.toggle('hidden', connected);
    const liveData = document.getElementById('ble-live-data');
    if (connected) { liveData.classList.remove('hidden'); liveData.style.display = 'flex'; }
    else { liveData.classList.add('hidden'); }
});

function bleRetry() {
    document.getElementById('ble-status-text').textContent = 'Connecting...';
    document.getElementById('ble-dot').style.background = 'var(--text-secondary)';
    document.getElementById('ble-retry-btn').classList.add('hidden');
    BleClient.connect();
}
BleClient.onCadence(rpm => {
    document.getElementById('ble-settings-cadence').textContent = rpm;
});
BleClient.onHR(bpm => {
    document.getElementById('ble-settings-hr').textContent = bpm;
});

function switchUser() {
    fetch('/api/users/deselect', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } })
        .then(() => window.location.href = '/select-user');
}
</script>
@endpush
