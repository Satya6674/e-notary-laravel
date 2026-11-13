@extends('layouts.app')

@section('title', __('Dashboard'))

@section('content')
<div class="en-page-head">
    <div><h1>{{ __('Welcome, :name', ['name' => $user->name]) }}</h1><p>{{ __('Track your applications and upcoming appointments.') }}</p></div>
    <a class="btn btn-primary" href="{{ route('user.services.index') }}"><i class="fas fa-plus me-2"></i>{{ __('New Application') }}</a>
</div>

<div class="row g-3 mb-4">
    @foreach([
        ['In Review', $inReview, 'fa-hourglass-half', false, route('user.requests')],
        ['Needs Correction', $needsCorrection, 'fa-pen-to-square', $needsCorrection > 0, route('user.requests', ['status' => 'correction_requested'])],
        ['Approved or Completed', $approvedRequests, 'fa-circle-check', false, route('user.requests')],
        ['Documents', $totalDocuments, 'fa-folder-open', false, route('user.documents')],
    ] as [$label, $value, $icon, $alert, $link])
        <div class="col-6 col-xl-3">
            <a class="card en-stat {{ $alert ? 'is-alert' : '' }}" href="{{ $link }}">
                <span class="en-stat-icon"><i class="fas {{ $icon }}"></i></span>
                <span><span class="en-stat-value d-block">{{ $value }}</span><span class="en-stat-label">{{ __($label) }}</span></span>
            </a>
        </div>
    @endforeach
</div>

@if(\App\Models\User::mobileVerificationSupported())
    <div class="card mb-4">
        <div class="card-header"><h2>{{ __('Verification status') }}</h2></div>
        <div class="card-body pb-0">@include('auth.partials.verification-status', ['user' => $user])</div>
    </div>
@endif

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header"><h2>{{ __('Recent Applications') }}</h2></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead><tr><th>{{ __('Reference') }}</th><th>{{ __('Type') }}</th><th>{{ __('Status') }}</th><th>{{ __('Submitted') }}</th></tr></thead>
                    <tbody>
                        @forelse($recentRequests as $application)
                            <tr>
                                <td class="text-nowrap"><a href="{{ route('user.requests.show', $application) }}">{{ $application->request_number }}</a></td>
                                <td>{{ __(ucwords(str_replace('_', ' ', $application->document_type))) }}</td>
                                <td>@include('partials.status-badge', ['status' => $application->status])</td>
                                <td class="text-nowrap">{{ $application->created_at?->ist('d M Y') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-4">{{ __('No applications yet.') }} <a href="{{ route('user.services.index') }}">{{ __('Start your first one.') }}</a></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header"><h2>{{ __('Upcoming Appointments') }}</h2></div>
            <div class="card-body">
                @forelse($upcoming as $appointment)
                    <p class="mb-2"><strong>{{ $appointment->scheduled_at->ist('d M Y H:i') }}</strong><small class="d-block text-muted">{{ $appointment->request?->request_number }} &middot; {{ __(ucfirst($appointment->status)) }}</small></p>
                @empty
                    <p class="text-muted mb-0">{{ __('No upcoming appointments.') }}</p>
                @endforelse
                <a class="btn btn-sm btn-outline-primary mt-2" href="{{ route('appointments.index') }}">{{ __('Appointments') }}</a>
            </div>
        </div>
    </div>
</div>
@endsection
