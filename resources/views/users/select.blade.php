@extends('layouts.app')

@section('title', 'Select Rider — SpinCoach')

@section('content')
<div class="container" style="display:flex;flex-direction:column;justify-content:center;min-height:100vh;">
    <h1 style="text-align:center;">SpinCoach</h1>
    <h2 style="text-align:center;">Who's riding?</h2>

    <div class="user-grid">
        @foreach($users as $user)
        <div class="user-card" onclick="selectUser({{ $user->id }})" style="border-color: {{ $user->color_hex }}; background: {{ $user->color_hex }}22;">
            <span class="emoji">{{ $user->avatar_emoji }}</span>
            <span class="name">{{ $user->name }}</span>
            <button class="delete-btn" onclick="event.stopPropagation(); openDeleteModal({{ $user->id }}, '{{ e($user->name) }}')" title="Delete rider">✕ Delete</button>
        </div>
        @endforeach

        <a href="/users/create" class="user-card add-new">
            <span class="emoji">+</span>
            <span class="name">Add Rider</span>
        </a>
    </div>
</div>

<div id="delete-modal" class="modal-overlay" style="display:none;" onclick="closeDeleteModal()">
    <div class="modal" onclick="event.stopPropagation()">
        <p class="modal-title">Delete <strong id="delete-name"></strong>?</p>
        <p class="modal-body">Their ride history will be kept. This just removes them from the picker.</p>
        <div class="modal-actions">
            <button class="btn-secondary" onclick="closeDeleteModal()">Cancel</button>
            <button class="btn-danger" onclick="confirmDelete()">Delete</button>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
let pendingDeleteId = null;

function openDeleteModal(userId, name) {
    pendingDeleteId = userId;
    document.getElementById('delete-name').textContent = name;
    document.getElementById('delete-modal').style.display = 'flex';
}

function closeDeleteModal() {
    pendingDeleteId = null;
    document.getElementById('delete-modal').style.display = 'none';
}

function confirmDelete() {
    if (!pendingDeleteId) return;
    fetch('/users/' + pendingDeleteId, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
    }).then(r => r.json()).then(data => {
        if (!data.error) window.location.reload();
    });
}

function selectUser(userId) {
    fetch('/users/select', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify({ user_id: userId }),
    })
    .then(r => r.json())
    .then(data => {
        if (!data.error) window.location.href = '/home';
    });
}
</script>
@endpush
