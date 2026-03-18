@extends('layouts.app')

@section('title', 'Session Detail — SpinCoach')

@section('content')
<div class="container" style="padding-top:20px;">
    <a href="/history" style="font-size:14px;color:var(--text-secondary);">&larr; Back to history</a>

    <h1 style="margin-top:16px;">{{ $session->workout?->name ?? 'Ad-hoc Ride' }}</h1>
    <h2>{{ $session->started_at?->format('M j, Y \a\t g:i A') }}</h2>

    <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-bottom:24px;">
        <div style="padding:16px;background:var(--bg-card);border-radius:12px;text-align:center;">
            <div style="font-size:28px;font-weight:700;">{{ $session->duration_actual_sec ? round($session->duration_actual_sec / 60) : '—' }}</div>
            <div style="font-size:12px;color:var(--text-secondary);text-transform:uppercase;">Minutes</div>
        </div>
        <div style="padding:16px;background:var(--bg-card);border-radius:12px;text-align:center;">
            <div style="font-size:28px;font-weight:700;">{{ $session->avg_cadence_rpm ?? '—' }}</div>
            <div style="font-size:12px;color:var(--text-secondary);text-transform:uppercase;">Avg RPM</div>
        </div>
        <div style="padding:16px;background:var(--bg-card);border-radius:12px;text-align:center;">
            <div style="font-size:28px;font-weight:700;">{{ $session->calories_estimate ?? '—' }}</div>
            <div style="font-size:12px;color:var(--text-secondary);text-transform:uppercase;">Calories</div>
        </div>
        <div style="padding:16px;background:var(--bg-card);border-radius:12px;text-align:center;">
            <div style="font-size:28px;font-weight:700;">{{ $session->completed ? 'Yes' : 'No' }}</div>
            <div style="font-size:12px;color:var(--text-secondary);text-transform:uppercase;">Completed</div>
        </div>
    </div>

    @if($session->intervals->count())
    <div class="section-label">Intervals</div>
    @foreach($session->intervals->sortBy('sequence') as $interval)
    <div style="padding:12px 16px;background:var(--bg-card);border-radius:8px;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center;">
        <div>
            <strong>{{ ucfirst($interval->phase_type) }}</strong>
            <span style="color:var(--text-secondary);font-size:13px;">{{ $interval->target_rpm_low }}–{{ $interval->target_rpm_high }} RPM</span>
        </div>
        <span style="font-size:14px;color:var(--text-secondary);">
            {{ $interval->actual_duration_sec ? round($interval->actual_duration_sec / 60, 1) . 'm' : $interval->duration_sec . 's planned' }}
        </span>
    </div>
    @endforeach
    @endif
</div>
@endsection
