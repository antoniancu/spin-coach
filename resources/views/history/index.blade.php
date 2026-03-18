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
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <h2 style="margin-bottom:0;">Your past sessions</h2>
        <button id="clear-all-btn" class="hidden" onclick="clearAll()" style="padding:6px 14px;font-size:12px;background:none;border:1px solid var(--accent-hard);color:var(--accent-hard);border-radius:var(--radius);cursor:pointer;">Clear all</button>
    </div>

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

        document.getElementById('clear-all-btn').classList.remove('hidden');

        res.data.sessions.forEach(s => {
            const row = document.createElement('div');
            row.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:12px;';
            row.id = 'session-' + s.id;

            const link = document.createElement('a');
            link.href = '/history/' + s.id;
            link.style.cssText = 'flex:1;display:block;padding:16px;background:var(--bg-card);border-radius:12px;text-decoration:none;color:var(--text-primary);';
            const date = new Date(s.started_at).toLocaleDateString();
            const mins = s.duration_actual_sec ? Math.round(s.duration_actual_sec / 60) : '?';
            link.innerHTML = `
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <strong>${s.workout_name || 'Ad-hoc ride'}</strong><br>
                        <small style="color:var(--text-secondary);">${date} &middot; ${mins} min</small>
                    </div>
                    <span style="font-size:12px;text-transform:uppercase;letter-spacing:1px;color:var(--text-secondary);">${s.intensity}</span>
                </div>`;

            const del = document.createElement('button');
            del.innerHTML = '&times;';
            del.style.cssText = 'background:none;border:none;color:var(--text-secondary);font-size:20px;cursor:pointer;padding:8px;opacity:0.4;';
            del.onclick = (e) => { e.stopPropagation(); deleteSession(s.id); };

            row.appendChild(link);
            row.appendChild(del);
            list.appendChild(row);
        });
    });

function deleteSession(id) {
    if (!confirm('Delete this session?')) return;
    fetch('/api/history/' + id, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
    }).then(() => {
        const row = document.getElementById('session-' + id);
        if (row) row.remove();
    });
}

function clearAll() {
    if (!confirm('Delete ALL ride history? This cannot be undone.')) return;
    fetch('/api/history', {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
    }).then(() => {
        document.getElementById('history-list').innerHTML = '<p style="text-align:center;color:var(--text-secondary);padding:40px;">No rides yet. Go ride!</p>';
        document.getElementById('clear-all-btn').classList.add('hidden');
    });
}

function switchUser() {
    fetch('/api/users/deselect', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } })
        .then(() => window.location.href = '/select-user');
}
</script>
@endpush
