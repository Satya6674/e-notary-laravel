@extends('layouts.app')

@section('title', __('Incidents'))

@section('content')
<x-ui.page-head :title="__('Incidents')" :subtitle="__('A record of security events and what was done about them.')">
    <a class="btn btn-outline-primary" href="{{ route('admin.security.index') }}">{{ __('Back to Security') }}</a>
</x-ui.page-head>

<x-ui.card :title="__('Record an Incident')" class="mb-3">
    <form method="POST" action="{{ route('admin.security.incidents.store') }}" class="row g-2">
        @csrf
        <div class="col-md-8"><x-ui.field name="title" :label="__('Title')" required maxlength="200" /></div>
        <div class="col-md-4 mb-3">
            <label class="form-label" for="severity">{{ __('Severity') }}</label>
            <select id="severity" name="severity" class="form-select">
                @foreach(\App\Models\SecurityIncident::SEVERITIES as $severity)
                    <option value="{{ $severity }}">{{ __(ucfirst($severity)) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-12 mb-3">
            <label class="form-label" for="description">{{ __('Description') }} <span class="en-required" aria-hidden="true">*</span></label>
            <textarea id="description" name="description" class="form-control" rows="3" maxlength="2000" required>{{ old('description') }}</textarea>
            <div class="form-text">{{ __('Do not include passwords, codes or personal details.') }}</div>
        </div>
        <div class="col-12"><button class="btn btn-primary" type="submit">{{ __('Record Incident') }}</button></div>
    </form>
</x-ui.card>

@forelse($incidents as $incident)
    <x-ui.card class="mb-3">
        <div class="d-flex flex-wrap justify-content-between gap-2">
            <strong>{{ $incident->title }}</strong>
            <span class="en-meta">{{ __(ucfirst($incident->severity)) }} &middot; {{ $incident->detected_at?->ist() }} IST</span>
        </div>
        <p class="small mt-1">{{ $incident->description }}</p>
        <form method="POST" action="{{ route('admin.security.incidents.update', $incident) }}" class="row g-2 align-items-end">
            @csrf
            @method('PATCH')
            <div class="col-md-3">
                <label class="form-label" for="incident-{{ $incident->id }}">{{ __('Status') }}</label>
                <select id="incident-{{ $incident->id }}" name="status" class="form-select">
                    @foreach(\App\Models\SecurityIncident::STATUSES as $status)
                        <option value="{{ $status }}" @selected($incident->status === $status)>{{ __(ucfirst($status)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-7">
                <label class="form-label" for="actions-{{ $incident->id }}">{{ __('Actions Taken') }}</label>
                <input id="actions-{{ $incident->id }}" name="actions" class="form-control" maxlength="2000" value="{{ $incident->actions }}">
            </div>
            <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">{{ __('Save') }}</button></div>
        </form>
    </x-ui.card>
@empty
    <div class="card"><div class="en-empty"><i class="fas fa-shield-halved" aria-hidden="true"></i>{{ __('No incidents recorded.') }}</div></div>
@endforelse
<div class="mt-3">{{ $incidents->links() }}</div>
@endsection
