@extends('layouts.app')

@section('title', __('Notifications'))

@section('content')
<div class="en-page-head">
    <div><h1>{{ __('Notifications') }}</h1></div>
    <form method="POST" action="{{ route('notifications.read-all') }}">@csrf<button class="btn btn-sm btn-outline-primary" type="submit">{{ __('Mark All as Read') }}</button></form>
</div>

<div class="card">
    <div class="list-group list-group-flush">
        @forelse($notifications as $notification)
            <a class="list-group-item list-group-item-action d-flex justify-content-between gap-3 {{ $notification->read_at ? '' : 'fw-bold' }}" href="{{ route('notifications.read', $notification->id) }}">
                <span>{{ __($notification->data['title'] ?? 'Notification') }} <small class="d-block text-muted fw-normal">{{ $notification->data['body'] ?? '' }}</small></span>
                <small class="text-muted text-nowrap fw-normal">{{ $notification->created_at?->ist('d M H:i') }}</small>
            </a>
        @empty
            <div class="text-center text-muted py-4">{{ __('No notifications yet.') }}</div>
        @endforelse
    </div>
</div>
<div class="mt-3">{{ $notifications->links() }}</div>
@endsection
