@extends('layouts.app')

@section('title', 'Select Rider — SpinCoach')

@section('content')
<div class="container" style="display:flex;flex-direction:column;justify-content:center;min-height:100vh;">
    <h1 style="text-align:center;">SpinCoach</h1>
    <h2 style="text-align:center;">Who's riding?</h2>

    <div class="user-grid">
        @foreach($users as $user)
        <div class="user-card" onclick="selectUser({{ $user->id }})" style="border-color: {{ $user->color_hex }}33;">
            <span class="emoji">{{ $user->avatar_emoji }}</span>
            <span class="name">{{ $user->name }}</span>
        </div>
        @endforeach

        <a href="/users/create" class="user-card add-new">
            <span class="emoji">+</span>
            <span class="name">Add Rider</span>
        </a>
    </div>
</div>
@endsection

@push('scripts')
<script>
function selectUser(userId) {
    fetch('/api/users/select', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify({ user_id: userId }),
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
