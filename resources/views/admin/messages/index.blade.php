@extends('layouts.app')

@section('title', __('Contact Messages'))

@section('content')
<x-ui.page-head :title="__('Contact Messages')" :subtitle="__('View and manage contact enquiries.')" />

<form method="GET" class="en-toolbar mb-3">
    <input type="text" name="search" class="form-control" placeholder="{{ __('Search by name, email, subject') }}" aria-label="{{ __('Search by name, email, subject') }}" value="{{ $search }}">
    <select name="status" class="form-select" aria-label="{{ __('Status') }}">
        <option value="all" @selected($status === 'all')>{{ __('All') }}</option>
        <option value="unread" @selected($status === 'unread')>{{ __('Unread') }}</option>
        <option value="read" @selected($status === 'read')>{{ __('Read') }}</option>
    </select>
    <div class="en-toolbar-action"><button type="submit" class="btn btn-primary">{{ __('Filter') }}</button></div>
</form>

<x-ui.card flush>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>{{ __('Name') }}</th>
                    <th>{{ __('Email') }}</th>
                    <th>{{ __('Subject') }}</th>
                    <th>{{ __('Message') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th>{{ __('Date') }}</th>
                    <th class="text-end">{{ __('Action') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($messages as $message)
                    <tr>
                        <td>{{ $message->name }}</td>
                        <td>{{ $message->email }}</td>
                        <td>{{ $message->subject }}</td>
                        <td class="text-truncate" style="max-width: 260px;" title="{{ $message->message }}">{{ $message->message }}</td>
                        <td>
                            <span class="en-badge {{ $message->is_read ? 'en-badge-approved' : 'en-badge-pending' }}">{{ $message->is_read ? __('Read') : __('Unread') }}</span>
                        </td>
                        <td class="text-nowrap">{{ $message->created_at?->ist('d M Y') }}</td>
                        <td>
                            <div class="en-actions">
                                @if(! $message->is_read)
                                    <form action="{{ route('admin.messages.read', $message) }}" method="POST">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-success">{{ __('Mark as Read') }}</button>
                                    </form>
                                @endif
                                <form action="{{ route('admin.messages.destroy', $message) }}" method="POST" data-confirm="{{ __('Delete this message?') }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Delete') }}</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty colspan="7" icon="fa-envelope">{{ __('No messages found.') }}</x-ui.empty>
                @endforelse
            </tbody>
        </table>
    </div>
</x-ui.card>

@if($messages instanceof \Illuminate\Contracts\Pagination\Paginator)
    <div class="mt-3">{{ $messages->links() }}</div>
@endif
@endsection
