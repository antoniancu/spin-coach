@extends('layouts.app')

@section('title', 'History — SpinCoach')

@section('content')
<div class="container">
    <div class="nav-bar">
        <a href="/home" class="logo">SpinCoach</a>
        <a href="/select-user" class="user-badge" onclick="event.preventDefault(); switchUser();">
            {{ request()->user()->avatar_emoji }} {{ request()->user()->name }}
        </a>
    </div>

    <h1>Ride History</h1>
    <h2>Your past sessions</h2>

    <div id="history-list"></div>
    <div id="loading" style="text-align:center;color:var(--text-secondary);padding:40px;">Loading...</div>
</div>
@endsection

@push('scripts')
<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;

fetch('/api/history', { headers: { 'Accept': 'application/json' } })
    .then(r => r.json())
    .then(res => {
        document.getElementById('loading').style.display = 'none';
        const list = document.getElementById('history-list');

        if (!res.data || !res.data.sessions.length) {
            list.innerHTML = '<p style="text-align:center;color:var(--text-secondary);padding:40px;">No rides yet. Go ride!</p>';
            return;
        }

        res.data.sessions.forEach(s => {
            const div = document.createElement('a');
            div.href = '/history/' + s.id;
            div.style.cssText = 'display:block;padding:16px;background:var(--bg-card);border-radius:12px;margin-bottom:12px;text-decoration:none;color:var(--text-primary);';
            const date = new Date(s.started_at).toLocaleDateString();
            const mins = s.duration_actual_sec ? Math.round(s.duration_actual_sec / 60) : '?';
            div.innerHTML = `
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <strong>${s.workout_name || 'Ad-hoc ride'}</strong><br>
                        <small style="color:var(--text-secondary);">${date} &middot; ${mins} min</small>
                    </div>
                    <span style="font-size:12px;text-transform:uppercase;letter-spacing:1px;color:var(--text-secondary);">${s.intensity}</span>
                </div>`;
            list.appendChild(div);
        });
    });

function switchUser() {
    fetch('/api/users/deselect', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } })
        .then(() => window.location.href = '/select-user');
}
</script>
@endpush
