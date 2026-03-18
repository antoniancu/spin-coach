@extends('layouts.app')

@section('title', 'Ride — SpinCoach')

@section('content')
<div class="ride-display" id="ride-display">
    <div class="ride-phase-label" id="phase-type">LOADING</div>
    <div class="ride-phase-name" id="phase-label">Preparing...</div>
    <div class="ride-timer" id="timer">--:--</div>
    <div class="ride-cadence" id="cadence-target"></div>
    <div class="ride-resistance" id="resistance-target"></div>

    <div class="ride-progress">
        <div class="ride-progress-bar" id="progress-bar" style="width:0%"></div>
    </div>

    <div class="ride-next" id="next-phase"></div>

    <button class="btn btn-stop" style="max-width:200px;margin-top:40px;" onclick="endRide()">End Ride</button>
</div>
@endsection

@push('scripts')
<script src="/js/audio.js"></script>
<script src="/js/workoutPlayer.js"></script>
<script>
const SESSION_ID = {{ $session->id }};
const PHASES = @json($session->workout ? $session->workout->phases : []);
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

document.addEventListener('DOMContentLoaded', () => {
    if (PHASES.length > 0) {
        WorkoutPlayer.init(SESSION_ID, PHASES, CSRF);
    }
});

function endRide() {
    WorkoutPlayer.abort();
}
</script>
@endpush
