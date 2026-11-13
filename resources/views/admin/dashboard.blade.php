@extends('layouts.app')

@section('title', __('Admin Dashboard'))

@section('content')
<div class="en-page-head">
    <div><h1>{{ __('Admin Dashboard') }}</h1><p>{{ __('Live figures from the database.') }}</p></div>
    <div class="d-flex gap-2">
        <a class="btn btn-primary" href="{{ route('admin.analytics.index') }}">{{ __('Analytics') }}</a>
        <a class="btn btn-outline-primary" href="{{ route('reports.index') }}">{{ __('Reports') }}</a>
    </div>
</div>

<div class="row g-3 mb-4">
    @foreach([
        ['Applications', $totalRequests, 'fa-file-signature', false, route('admin.requests.index')],
        ['Awaiting Assignment', $pendingRequests, 'fa-inbox', $pendingRequests > 0, route('admin.requests.index', ['status' => 'pending'])],
        ['Citizens', $totalUsers, 'fa-users', false, route('admin.users.index', ['role' => 'public'])],
        ['Notaries', $totalNotaries, 'fa-user-tie', false, route('admin.notaries.index')],
        ['Notaries Awaiting Approval', $pendingNotaries, 'fa-user-clock', $pendingNotaries > 0, route('admin.notaries.index', ['status' => 'pending'])],
        ['Documents', $totalDocuments, 'fa-folder-open', false, route('admin.documents.index')],
        ['Open Appointments', $openAppointments, 'fa-calendar-check', false, route('appointments.index')],
        ['Open Tickets', $openTickets, 'fa-life-ring', $openTickets > 0, route('support.index')],
    ] as [$label, $value, $icon, $alert, $link])
        <div class="col-6 col-xl-3">
            <a class="card en-stat {{ $alert ? 'is-alert' : '' }}" href="{{ $link }}">
                <span class="en-stat-icon"><i class="fas {{ $icon }}"></i></span>
                <span><span class="en-stat-value d-block">{{ $value }}</span><span class="en-stat-label">{{ __($label) }}</span></span>
            </a>
        </div>
    @endforeach
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header"><h2>{{ __('Recent Applications') }}</h2></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead><tr><th>{{ __('Reference') }}</th><th>{{ __('Citizen') }}</th><th>{{ __('Notary') }}</th><th>{{ __('Status') }}</th></tr></thead>
                    <tbody>
                        @forelse($recentRequests as $application)
                            <tr>
                                <td class="text-nowrap"><a href="{{ route('admin.requests.show', $application) }}">{{ $application->request_number }}</a></td>
                                <td>{{ $application->user?->name ?? '-' }}</td>
                                <td>{{ $application->notary?->name ?? __('Not assigned') }}</td>
                                <td>@include('partials.status-badge', ['status' => $application->status])</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-4">{{ __('No applications yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header"><h2>{{ __('Applications by Status') }}</h2></div>
            <div class="card-body">
                @forelse($byStatus as $name => $count)
                    @php($share = $totalRequests ? round($count / $totalRequests * 100) : 0)
                    <div class="d-flex justify-content-between small mb-1"><span>{{ __(ucwords(str_replace('_', ' ', $name))) }}</span><strong>{{ $count }}</strong></div>
                    <div class="progress mb-3" style="height:7px;" role="progressbar" aria-valuenow="{{ $share }}" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:{{ $share }}%;background:var(--en-navy);"></div></div>
                @empty
                    <p class="text-muted mb-0">{{ __('No applications yet.') }}</p>
                @endforelse
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="card">
            <div class="card-header"><h2>{{ __('Recent Activity') }}</h2></div>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead><tr><th>{{ __('When') }}</th><th>{{ __('User') }}</th><th>{{ __('Action') }}</th></tr></thead>
                    <tbody>
                        @forelse($recentAudit as $entry)
                            <tr><td class="text-nowrap">{{ $entry->created_at?->ist('d M Y H:i') }}</td><td>{{ $entry->user?->name ?? __('System') }}</td><td><code>{{ $entry->action }}</code></td></tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted py-4">{{ __('No activity recorded yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
