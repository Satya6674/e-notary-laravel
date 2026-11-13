@extends('layouts.app')

@section('title', __('Analytics'))

@section('content')
@php
    $query = ['from' => $from, 'to' => $to];
    $na = __('Not available');
    $show = fn ($n) => $n === null ? $na : number_format($n);
    $citizenColour = '#171B34';
    $notaryColour = '#D5AF48';
@endphp

<div class="en-page-head">
    <div>
        <h1>{{ __('Reports & Analytics') }}</h1>
        <p>{{ __('Counted from the live database each time this page opens. Dates are Indian Standard Time.') }}</p>
    </div>
    <div class="d-flex flex-wrap gap-2 en-no-print">
        <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.analytics.csv', $query) }}"><i class="fas fa-file-csv me-1" aria-hidden="true"></i>CSV</a>
        <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.analytics.xlsx', $query) }}"><i class="fas fa-file-excel me-1" aria-hidden="true"></i>Excel</a>
        <a class="btn btn-sm btn-primary" href="{{ route('admin.analytics.pdf', $query) }}"><i class="fas fa-file-pdf me-1" aria-hidden="true"></i>PDF</a>
        <button class="btn btn-sm btn-outline-secondary" type="button" onclick="window.print()"><i class="fas fa-print me-1" aria-hidden="true"></i>{{ __('Print') }}</button>
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('reports.index') }}"><i class="fas fa-table-list me-1" aria-hidden="true"></i>{{ __('Application report') }}</a>
    </div>
</div>

<div class="card mb-4 en-no-print">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end" novalidate>
            <div class="col-sm-4 col-lg-3">
                <label class="form-label" for="from">{{ __('From') }}</label>
                <input id="from" type="date" name="from" class="form-control @error('from') is-invalid @enderror" value="{{ old('from', $from) }}" @error('from') aria-describedby="from-error" @enderror>
                @error('from')<div id="from-error" class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-4 col-lg-3">
                <label class="form-label" for="to">{{ __('To') }}</label>
                <input id="to" type="date" name="to" class="form-control @error('to') is-invalid @enderror" value="{{ old('to', $to) }}" @error('to') aria-describedby="to-error" @enderror>
                @error('to')<div id="to-error" class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-4 col-lg-2"><button class="btn btn-primary w-100" type="submit">{{ __('Apply') }}</button></div>
            <div class="col-lg-4 small text-muted">{{ __('The totals below are current. Trends and exports use the selected dates.') }}</div>
        </form>
    </div>
</div>

<p class="d-none d-print-block fw-semibold">{{ __('Report period') }}: {{ $from }} – {{ $to }} (IST)</p>

<h2 class="h5 mb-3">{{ __('Current totals') }}</h2>
<div class="row g-3 mb-4">
    @foreach([
        ['Total Citizens', $snapshot['citizens'], 'fa-users', route('admin.users.index', ['role' => 'public'])],
        ['Total Notaries', $snapshot['notaries'], 'fa-user-tie', route('admin.notaries.index')],
        ['Approved Notaries', $snapshot['approved_notaries'], 'fa-user-check', route('admin.notaries.index', ['status' => 'verified'])],
        ['Pending Notaries', $snapshot['pending_notaries'], 'fa-user-clock', route('admin.notaries.index', ['status' => 'pending'])],
        ['Rejected Notaries', $snapshot['rejected_notaries'], 'fa-user-xmark', route('admin.notaries.index', ['status' => 'rejected'])],
        ['Total appointments', $snapshot['appointments']['total'], 'fa-calendar', route('appointments.index')],
        ['Pending appointments', $snapshot['appointments']['pending'], 'fa-calendar-day', route('appointments.index')],
        ['Completed appointments', $snapshot['appointments']['completed'], 'fa-calendar-check', route('appointments.index')],
        ['Cancelled appointments', $snapshot['appointments']['cancelled'], 'fa-calendar-xmark', route('appointments.index')],
        ['Deleted accounts', $snapshot['deleted_accounts'], 'fa-user-slash', \App\Models\User::softDeleteSupported() ? route('admin.users.deleted') : null],
        ['Restored accounts', $snapshot['restored_accounts'], 'fa-user-plus', \App\Models\User::softDeleteSupported() ? route('admin.users.deleted') : null],
        ['Recovery requests', $snapshot['recovery']['total'] ?? null, 'fa-user-shield', \App\Models\User::accountRecoverySupported() ? route('admin.recovery.index') : null],
        ['Lost mailbox replacement requests', $snapshot['replacements']['total'] ?? null, 'fa-envelope-open-text', null],
    ] as [$label, $value, $icon, $link])
        <div class="col-6 col-md-4 col-xl-3">
            <{{ $link ? 'a' : 'div' }} class="card en-stat" @if($link) href="{{ $link }}" @endif>
                <span class="en-stat-icon"><i class="fas {{ $icon }}" aria-hidden="true"></i></span>
                <span>
                    <span class="en-stat-value d-block" @if($value === null) aria-label="{{ $na }}" @endif>{{ $value === null ? '–' : number_format($value) }}</span>
                    <span class="en-stat-label">{{ __($label) }}</span>
                    @if($value === null)<span class="d-block small text-muted">{{ $na }}: {{ __('Needs a pending database update') }}</span>@endif
                </span>
            </{{ $link ? 'a' : 'div' }}>
        </div>
    @endforeach
</div>

@if($snapshot['recovery'] || $snapshot['replacements'])
<div class="row g-3 mb-4">
    @foreach(array_filter(['Recovery requests by status' => $snapshot['recovery'], 'Lost mailbox replacements by status' => $snapshot['replacements']]) as $title => $counts)
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h2>{{ __($title) }}</h2></div>
                <ul class="list-group list-group-flush">
                    @forelse(\Illuminate\Support\Arr::except($counts, 'total') as $state => $count)
                        <li class="list-group-item d-flex justify-content-between"><span>{{ __(ucwords(str_replace('_', ' ', $state))) }}</span><strong>{{ $count }}</strong></li>
                    @empty
                        <li class="list-group-item text-muted">{{ __('None yet.') }}</li>
                    @endforelse
                </ul>
            </div>
        </div>
    @endforeach
</div>
@endif

<h2 class="h5 mb-3">{{ __('Registrations') }} <small class="text-muted fw-normal">{{ $from }} – {{ $to }}</small></h2>
<div class="row g-3 mb-3">
    @foreach([
        ['New registrations', $period['registrations']['total']],
        ['Citizens', $period['registrations']['citizens']],
        ['Notaries', $period['registrations']['notaries']],
        ['Notary approvals', $period['decisions']['approved']],
        ['Notary rejections', $period['decisions']['rejected']],
    ] as [$label, $value])
        <div class="col-6 col-md-4 col-xl"><div class="card en-stat"><span><span class="en-stat-value d-block">{{ number_format($value) }}</span><span class="en-stat-label">{{ __($label) }}</span></span></div></div>
    @endforeach
</div>

<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header"><h2>{{ __('Daily registrations') }}</h2></div>
            <div class="card-body">
                @if($period['registrations']['daily'] === null)
                    <p class="text-muted mb-0">{{ __('Choose a range of :days days or less to see daily figures.', ['days' => $maxDaily]) }}</p>
                @else
                    @include('admin.analytics.partials.bars', ['series' => $period['registrations']['daily'], 'caption' => __('Daily registrations'), 'keys' => ['citizens' => [__('Citizens'), $citizenColour], 'notaries' => [__('Notaries'), $notaryColour]]])
                @endif
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h2>{{ __('Weekly registrations') }}</h2></div>
            <div class="card-body">
                @include('admin.analytics.partials.bars', ['series' => $period['registrations']['weekly'], 'caption' => __('Weekly registrations'), 'keys' => ['citizens' => [__('Citizens'), $citizenColour], 'notaries' => [__('Notaries'), $notaryColour]]])
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h2>{{ __('Monthly registrations') }}</h2></div>
            <div class="card-body">
                @include('admin.analytics.partials.bars', ['series' => $period['registrations']['monthly'], 'caption' => __('Monthly registrations'), 'keys' => ['citizens' => [__('Citizens'), $citizenColour], 'notaries' => [__('Notaries'), $notaryColour]]])
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h2>{{ __('Citizen vs Notary') }}</h2></div>
            <div class="card-body">
                @php($regTotal = $period['registrations']['total'])
                @foreach([[__('Citizens'), $period['registrations']['citizens'], $citizenColour], [__('Notaries'), $period['registrations']['notaries'], $notaryColour]] as [$label, $count, $colour])
                    @php($share = $regTotal ? round($count / $regTotal * 100, 1) : 0)
                    <div class="d-flex justify-content-between small mb-1"><span>{{ $label }}</span><strong>{{ $count }} ({{ $share }}%)</strong></div>
                    <div class="progress mb-3" style="height:9px;" role="progressbar" aria-label="{{ $label }}" aria-valuenow="{{ $share }}" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:{{ $share }}%;background:{{ $colour }};"></div></div>
                @endforeach
                @if(! $regTotal)<p class="text-muted small mb-0">{{ __('No registrations in this period.') }}</p>@endif
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h2>{{ __('Notary approval and rejection trend') }}</h2></div>
            <div class="card-body">
                @include('admin.analytics.partials.bars', ['series' => $period['decisions']['monthly'], 'caption' => __('Notary approval and rejection trend'), 'keys' => ['approved' => [__('Approved'), '#1D6B38'], 'rejected' => [__('Rejected'), '#C9252B']]])
            </div>
        </div>
    </div>
</div>

<h2 class="h5 mb-3">{{ __('Appointments') }} <small class="text-muted fw-normal">{{ __('by appointment date') }}, {{ $from }} – {{ $to }}</small></h2>
<div class="row g-3 mb-3">
    <div class="col-6 col-md-4 col-xl"><div class="card en-stat"><span><span class="en-stat-value d-block">{{ number_format($period['appointments']['total']) }}</span><span class="en-stat-label">{{ __('Appointments in period') }}</span></span></div></div>
    @foreach($period['appointments']['byStatus'] as $state => $count)
        <div class="col-6 col-md-4 col-xl"><div class="card en-stat"><span><span class="en-stat-value d-block">{{ number_format($count) }}</span><span class="en-stat-label">{{ __(ucfirst($state)) }}</span></span></div></div>
    @endforeach
    <div class="col-6 col-md-4 col-xl"><div class="card en-stat"><span><span class="en-stat-value d-block">{{ $period['appointments']['completion'] === null ? '–' : $period['appointments']['completion'].'%' }}</span><span class="en-stat-label">{{ __('Completion rate') }}</span></span></div></div>
</div>
<p class="small text-muted">{{ __('Completion rate is completed appointments as a share of those completed or cancelled. Open appointments are not counted.') }}</p>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h2>{{ __('Monthly appointment trend') }}</h2></div>
            <div class="card-body">
                @include('admin.analytics.partials.bars', ['series' => $period['appointments']['monthly'], 'caption' => __('Monthly appointment trend'), 'keys' => [
                    'requested' => [__('Requested'), '#8A8DA3'], 'confirmed' => [__('Confirmed'), '#171B34'], 'completed' => [__('Completed'), '#1D6B38'], 'cancelled' => [__('Cancelled'), '#C9252B'],
                ]])
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h2>{{ __('Status distribution') }}</h2></div>
            <div class="card-body">
                @php($apptTotal = $period['appointments']['total'])
                @foreach($period['appointments']['byStatus'] as $state => $count)
                    @php($share = $apptTotal ? round($count / $apptTotal * 100, 1) : 0)
                    <div class="d-flex justify-content-between small mb-1"><span>{{ __(ucfirst($state)) }}</span><strong>{{ $count }} ({{ $share }}%)</strong></div>
                    <div class="progress mb-3" style="height:9px;" role="progressbar" aria-label="{{ __(ucfirst($state)) }}" aria-valuenow="{{ $share }}" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:{{ $share }}%;background:var(--en-navy);"></div></div>
                @endforeach
                @if(! $apptTotal)<p class="text-muted small mb-0">{{ __('No appointments in this period.') }}</p>@endif
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="card">
            <div class="card-header"><h2>{{ __('Notary-wise statistics') }}</h2></div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th scope="col">{{ __('Notary') }}</th><th scope="col" class="text-end">{{ __('Total') }}</th><th scope="col" class="text-end">{{ __('Open') }}</th><th scope="col" class="text-end">{{ __('Completed') }}</th><th scope="col" class="text-end">{{ __('Cancelled') }}</th><th scope="col" class="text-end">{{ __('Completion rate') }}</th></tr></thead>
                    <tbody>
                        @forelse($period['appointments']['byNotary'] as $row)
                            <tr>
                                <td>{{ $row['name'] }}</td>
                                <td class="text-end">{{ $row['total'] }}</td>
                                <td class="text-end">{{ $row['open'] }}</td>
                                <td class="text-end">{{ $row['completed'] }}</td>
                                <td class="text-end">{{ $row['cancelled'] }}</td>
                                <td class="text-end">{{ $row['completion'] === null ? '–' : $row['completion'].'%' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">{{ __('No appointments in this period.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
