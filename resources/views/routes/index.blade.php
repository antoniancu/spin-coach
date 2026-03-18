@extends('layouts.app')

@section('title', 'Routes — SpinCoach')

@section('content')
<div class="container">
    <div class="nav-bar">
        <a href="/home" class="logo">SpinCoach</a>
    </div>

    <h1>Virtual Routes</h1>
    <h2>Choose a route for your ride</h2>

    <div id="routes-list"></div>
    <div id="loading" style="text-align:center;color:var(--text-secondary);padding:40px;">Loading...</div>
</div>
@endsection

@push('scripts')
<script>
fetch('/api/routes', { headers: { 'Accept': 'application/json' } })
    .then(r => r.json())
    .then(res => {
        document.getElementById('loading').style.display = 'none';
        const list = document.getElementById('routes-list');

        if (!res.data || !res.data.length) {
            list.innerHTML = '<p style="text-align:center;color:var(--text-secondary);padding:40px;">No routes available.</p>';
            return;
        }

        res.data.forEach(route => {
            const div = document.createElement('div');
            div.style.cssText = 'padding:16px;background:var(--bg-card);border-radius:12px;margin-bottom:12px;cursor:pointer;';
            div.innerHTML = `
                <strong>${route.name}</strong>
                <div style="font-size:13px;color:var(--text-secondary);margin-top:4px;">
                    ${route.country} &middot; ${route.total_distance_km} km &middot; ${route.difficulty}
                    ${route.elevation_gain_m ? ' &middot; ' + route.elevation_gain_m + 'm gain' : ''}
                </div>
                <div style="font-size:13px;color:var(--text-secondary);margin-top:4px;">${route.description || ''}</div>`;
            list.appendChild(div);
        });
    });
</script>
@endpush
