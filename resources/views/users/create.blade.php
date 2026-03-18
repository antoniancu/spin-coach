@extends('layouts.app')

@section('title', 'Add Rider — SpinCoach')

@section('content')
<div class="container" style="padding-top:40px;">
    <h1>Add Rider</h1>
    <h2>Pick a name, emoji, and color</h2>

    <div class="form-group">
        <label for="name">Name</label>
        <input type="text" id="name" maxlength="32" placeholder="Your name" autofocus>
    </div>

    <div class="form-group">
        <label>Avatar</label>
        <div class="emoji-options">
            @foreach(['🚴', '🚴‍♀️', '🏃', '🏃‍♀️', '💪', '⚡', '🔥', '🌟', '🦁', '🐯'] as $emoji)
            <button class="emoji-option{{ $loop->first ? ' selected' : '' }}" data-emoji="{{ $emoji }}">{{ $emoji }}</button>
            @endforeach
        </div>
    </div>

    <div class="form-group">
        <label>Color</label>
        <div class="color-options">
            @foreach(['#7C3AED', '#059669', '#DC2626', '#2563EB', '#D97706', '#EC4899', '#0891B2', '#65A30D'] as $color)
            <div class="color-swatch{{ $loop->first ? ' selected' : '' }}"
                 style="background:{{ $color }}"
                 data-color="{{ $color }}"
                 onclick="selectColor(this)"></div>
            @endforeach
        </div>
    </div>

    <button class="btn btn-primary" onclick="createUser()">Create Rider</button>
</div>
@endsection

@push('scripts')
<script>
let selectedEmoji = '🚴';
let selectedColor = '#7C3AED';

document.querySelectorAll('.emoji-option').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.emoji-option').forEach(b => b.classList.remove('selected'));
        btn.classList.add('selected');
        selectedEmoji = btn.dataset.emoji;
    });
});

function selectColor(el) {
    document.querySelectorAll('.color-swatch').forEach(s => s.classList.remove('selected'));
    el.classList.add('selected');
    selectedColor = el.dataset.color;
}

function createUser() {
    const name = document.getElementById('name').value.trim();
    if (!name) return;

    fetch('/users', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify({
            name: name,
            avatar_emoji: selectedEmoji,
            color_hex: selectedColor,
        }),
    })
    .then(r => r.json())
    .then(data => {
        if (!data.error) {
            window.location.href = '/home';
        }
    });
}
</script>
@endpush
