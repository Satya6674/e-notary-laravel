@extends('pdf.layout')

@section('body')
<p class="muted" style="font-size:9pt;"><b>{{ __('Status') }}:</b> {{ $filters['status'] ? __($report->label($filters['status'])) : __('All') }} &nbsp;|&nbsp; <b>{{ __('From') }}:</b> {{ $filters['from'] ?: '-' }} &nbsp;|&nbsp; <b>{{ __('To') }}:</b> {{ $filters['to'] ?: '-' }} &nbsp;|&nbsp; <b>{{ __('Scope') }}:</b> {{ __($scope) }}</p>

<table width="100%" cellpadding="0" cellspacing="0" style="margin-top:12pt;"><tr>
    <td style="width:50%; vertical-align:top; padding-right:8pt;">
        <h3>{{ __('Applications by Status') }}</h3>
        <table class="grid">
            <thead><tr><th>{{ __('Status') }}</th><th style="width:22%;">{{ __('Total') }}</th></tr></thead>
            <tbody>
                @forelse($statistics['byStatus'] as $status => $total)
                    <tr><td>{{ __($report->label($status)) }}</td><td>{{ $total }}</td></tr>
                @empty
                    <tr><td colspan="2" class="empty">{{ __('No applications match these filters.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </td>
    <td style="width:50%; vertical-align:top; padding-left:8pt;">
        <h3>{{ __('Applications by Service') }}</h3>
        <table class="grid">
            <thead><tr><th>{{ __('Service') }}</th><th style="width:22%;">{{ __('Total') }}</th></tr></thead>
            <tbody>
                @forelse($statistics['byService'] as $service => $total)
                    <tr><td>{{ __($service) }}</td><td>{{ $total }}</td></tr>
                @empty
                    <tr><td colspan="2" class="empty">{{ __('No applications match these filters.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </td>
</tr></table>

<table width="100%" cellpadding="0" cellspacing="0" style="margin-top:12pt;"><tr>
    @if($statistics['workload'] !== null)
        <td style="width:50%; vertical-align:top; padding-right:8pt;">
            <h3>{{ __('Notary Workload') }}</h3>
            <table class="grid">
                <thead><tr><th>{{ __('Notary') }}</th><th style="width:22%;">{{ __('Open') }}</th><th style="width:22%;">{{ __('Total') }}</th></tr></thead>
                <tbody>
                    @forelse($statistics['workload'] as $row)
                        <tr><td>{{ $row['name'] }}</td><td>{{ $row['open'] }}</td><td>{{ $row['total'] }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="empty">{{ __('No applications are assigned.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </td>
    @endif
    <td style="width:50%; vertical-align:top; padding-left:{{ $statistics['workload'] !== null ? 8 : 0 }}pt;">
        <h3>{{ __('Appointments') }}</h3>
        <table class="grid">
            <thead><tr><th>{{ __('Status') }}</th><th style="width:22%;">{{ __('Total') }}</th></tr></thead>
            <tbody>
                @forelse($statistics['appointments'] as $status => $total)
                    <tr><td>{{ __(ucfirst($status)) }}</td><td>{{ $total }}</td></tr>
                @empty
                    <tr><td colspan="2" class="empty">{{ __('No appointments yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </td>
</tr></table>

@if($applications->isNotEmpty())
<pagebreak />
<h2>{{ __('Applications') }}</h2>
@if($statistics['total'] > $applications->count())
    <div class="note">{{ __('Showing the latest :shown of :total records. Narrow the filters or use the CSV export for all records.', ['shown' => $applications->count(), 'total' => $statistics['total']]) }}</div>
@endif
<table class="grid" repeat_header="1">
    <thead><tr>
        <th style="width:17%;">{{ __('Reference') }}</th><th>{{ __('Applicant') }}</th><th style="width:15%;">{{ __('Type') }}</th><th style="width:13%;">{{ __('Status') }}</th>
        <th style="width:17%;">{{ __('Notary') }}</th><th style="width:9%;">{{ __('Submitted') }}</th><th style="width:9%;">{{ __('Decided') }}</th>
    </tr></thead>
    <tbody>
        @foreach($applications as $i => $row)
            <tr class="{{ $i % 2 ? 'alt' : '' }}">
                <td>{{ $row->request_number }}</td><td>{{ $row->user?->name }}</td><td>{{ __($report->label($row->document_type)) }}</td>
                <td>{{ __($report->label($row->status)) }}</td><td>{{ $row->notary?->name ?? '-' }}</td>
                <td>{{ $row->created_at?->ist('d M Y') }}</td><td>{{ $row->decided_at?->ist('d M Y') ?? '-' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
@endif
@endsection
