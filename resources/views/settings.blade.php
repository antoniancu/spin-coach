@extends('layouts.app')

@section('title', 'Settings — SpinCoach')

@section('content')
<div class="container">
    <div class="nav-bar">
        <a href="/home" class="logo">SpinCoach</a>
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
        <div style="padding:16px;background:var(--bg-card);border-radius:var(--radius);color:var(--text-secondary);font-size:14px;">
            WebSocket: <code>{{ config('services.ble_bridge.ws_url') }}</code><br>
            <span id="ble-status">Not connected (feature coming soon)</span>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="/js/spotify.js"></script>
<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;
document.addEventListener('DOMContentLoaded', () => SpotifyUI.init(csrf));

function switchUser() {
    fetch('/api/users/deselect', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } })
        .then(() => window.location.href = '/select-user');
}
</script>
@endpush
