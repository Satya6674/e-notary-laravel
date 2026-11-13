@extends('layouts.app')

@section('title', __('Privacy Requests'))

@section('content')
<x-ui.page-head :title="__('Privacy Requests')" :subtitle="__('Handle each request by hand. Completing one here does not change or delete any data.')">
    <a class="btn btn-outline-primary" href="{{ route('admin.security.index') }}">{{ __('Back to Security') }}</a>
</x-ui.page-head>

@forelse($requests as $item)
    <x-ui.card class="mb-3">
        <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
            <strong>{{ __(ucfirst($item->type)) }} &middot; {{ $item->user?->name }}</strong>
            <span class="en-meta">{{ $item->created_at?->ist() }} IST</span>
        </div>
        @if($item->details)<p class="small">{{ $item->details }}</p>@endif
        <form method="POST" action="{{ route('admin.security.privacy.update', $item) }}" class="row g-2 align-items-end">
            @csrf
            @method('PATCH')
            <div class="col-md-3">
                <label class="form-label" for="status-{{ $item->id }}">{{ __('Status') }}</label>
                <select id="status-{{ $item->id }}" name="status" class="form-select">
                    @foreach(\App\Models\PrivacyRequest::STATUSES as $status)
                        <option value="{{ $status }}" @selected($item->status === $status)>{{ __(ucwords(str_replace('_', ' ', $status))) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-7">
                <label class="form-label" for="resolution-{{ $item->id }}">{{ __('Outcome, shown to the person') }}</label>
                <input id="resolution-{{ $item->id }}" name="resolution" class="form-control" maxlength="1000" value="{{ $item->resolution }}">
            </div>
            <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">{{ __('Save') }}</button></div>
        </form>
    </x-ui.card>
@empty
    <div class="card"><div class="en-empty"><i class="fas fa-user-shield" aria-hidden="true"></i>{{ __('No privacy requests yet.') }}</div></div>
@endforelse
<div class="mt-3">{{ $requests->links() }}</div>
@endsection
