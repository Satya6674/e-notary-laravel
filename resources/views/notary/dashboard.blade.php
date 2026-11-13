@extends('layouts.app')

@section('title', __('Notary Dashboard'))

@section('content')
<div class="en-page-head">
    <div><h1>{{ __('Welcome, :name', ['name' => auth()->user()->name]) }}</h1><p>{{ __('Applications assigned to you.') }}</p></div>
    <a class="btn btn-primary" href="{{ route('notary.requests') }}">{{ __('Assigned Applications') }}</a>
</div>

<div class="row g-3 mb-4">
    @foreach([
        ['Awaiting Decision', $pendingCount, 'fa-hourglass-half', $pendingCount > 0, 'assigned'],
        ['Awaiting Correction', $correctionCount, 'fa-pen-to-square', false, 'correction_requested'],
        ['Approved', $approvedCount, 'fa-circle-check', false, 'approved'],
        ['Completed', $completedCases, 'fa-award', false, 'completed'],
        ['Rejected', $rejectedCount, 'fa-circle-xmark', false, 'rejected'],
    ] as [$label, $value, $icon, $alert, $status])
        <div class="col-6 col-xl">
            <a class="card en-stat {{ $alert ? 'is-alert' : '' }}" href="{{ route('notary.requests', ['status' => $status]) }}">
                <span class="en-stat-icon"><i class="fas {{ $icon }}"></i></span>
                <span><span class="en-stat-value d-block">{{ $value }}</span><span class="en-stat-label">{{ __($label) }}</span></span>
            </a>
        </div>
    @endforeach
</div>

@if(\App\Models\User::mobileVerificationSupported())
    <div class="card mb-4">
        <div class="card-header"><h2>{{ __('Verification status') }}</h2></div>
        <div class="card-body pb-0">
            @include('auth.partials.verification-status', ['user' => auth()->user()])
            @if(! auth()->user()->hasVerifiedMobile() && ! auth()->user()->mobileSimulationAccepted() && ! auth()->user()->mobileFallbackVerified() && auth()->user()->mobile)
                <p class="mb-3"><a href="{{ route('verification.mobile.notice') }}">{{ __('Verify your mobile number') }}</a></p>
            @endif
        </div>
    </div>
@endif

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header"><h2>{{ __('Recent Applications') }}</h2></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead><tr><th>{{ __('Reference') }}</th><th>{{ __('Citizen') }}</th><th>{{ __('Type') }}</th><th>{{ __('Status') }}</th></tr></thead>
                    <tbody>
                        @forelse($recentRequests as $application)
                            <tr>
                                <td class="text-nowrap"><a href="{{ route('notary.request.show', $application->id) }}">{{ $application->request_number }}</a></td>
                                <td>{{ $application->user?->name ?? '-' }}</td>
                                <td>{{ __(ucwords(str_replace('_', ' ', $application->document_type))) }}</td>
                                <td>@include('partials.status-badge', ['status' => $application->status])</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-4">{{ __('Nothing has been assigned to you yet.') }}</td></tr>
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
