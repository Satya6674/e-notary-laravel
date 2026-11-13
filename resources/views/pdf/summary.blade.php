@extends('pdf.layout')

@section('body')
@if($certificateProblem)<div class="warn">{{ __('A certificate on this application is no longer valid, because a stored file is missing or has changed.') }}</div>@endif
<h2>{{ __('Application Details') }}</h2>
<table class="fields">
    <tr><td class="k">{{ __('Reference') }}</td><td>{{ $application->request_number }}</td></tr>
    <tr><td class="k">{{ __('Status') }}</td><td>{{ __($report->label($application->status)) }}</td></tr>
    @if($application->service_details)
        <tr><td class="k">{{ __('Service') }}</td><td>{{ __($application->service_details['service'] ?? '') }}</td></tr>
    @endif
    <tr><td class="k">{{ __('Document Type') }}</td><td>{{ __($report->label($application->document_type)) }}</td></tr>
    <tr><td class="k">{{ __('Applicant') }}</td><td>{{ $application->user?->name ?? '-' }}</td></tr>
    <tr><td class="k">{{ __('Assigned Notary') }}</td><td>{{ $application->notary?->name ?? __('Not assigned') }}</td></tr>
    <tr><td class="k">{{ __('Submitted') }}</td><td>{{ $application->created_at?->ist() }} IST</td></tr>
    <tr><td class="k">{{ __('Last Decision') }}</td><td>{{ $application->decided_at ? $application->decided_at->ist().' IST' : __('None recorded') }}</td></tr>
    <tr><td class="k">{{ __('Purpose') }}</td><td>{{ $application->purpose }}</td></tr>
    @include('pdf.partials.application-details')
    @if($application->remarks)<tr><td class="k">{{ __('Notes') }}</td><td>{{ $application->remarks }}</td></tr>@endif
    @if($application->decision_remarks)<tr><td class="k">{{ __('Decision Remarks') }}</td><td>{{ $application->decision_remarks }}</td></tr>@endif
</table>

<h2>{{ __('Document Checklist') }}</h2>
<table class="grid" repeat_header="1">
    <thead><tr>
        <th>{{ __('Document') }}</th><th style="width:15%;">{{ __('Requirement') }}</th><th style="width:14%;">{{ __('Status') }}</th>
        <th style="width:10%;">{{ __('Size') }}</th><th style="width:19%;">{{ __('Uploaded') }}</th><th style="width:15%;">SHA-256</th>
    </tr></thead>
    <tbody>
        @forelse($checklist as $i => $row)
            <tr class="{{ $i % 2 ? 'alt' : '' }}">
                <td>{{ __($row['name']) }}</td>
                <td>{{ $row['required'] === null ? '-' : __($row['required'] ? 'Required' : 'Optional') }}</td>
                <td>@if($row['document'] && ! $row['intact'])<span class="tag">{{ __('File could not be confirmed') }}</span>@elseif($row['document'])<span class="ok">{{ __('File uploaded') }}</span>@else<span class="{{ $row['required'] ? 'tag' : 'muted' }}">{{ __($row['required'] ? 'Missing' : 'Not provided') }}</span>@endif</td>
                <td>{{ $row['document']?->file_size ? number_format($row['document']->file_size / 1024, 1).' KB' : '-' }}</td>
                <td>{{ $row['document']?->created_at?->ist() ?? '-' }}</td>
                <td class="mono">{{ $row['document']?->file_hash ? substr($row['document']->file_hash, 0, 12).'...' : '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty">{{ __('No documents attached.') }}</td></tr>
        @endforelse
    </tbody>
</table>

@if($application->appointments->isNotEmpty())
    <h2>{{ __('Appointments') }}</h2>
    <table class="grid" repeat_header="1">
        <thead><tr><th>{{ __('Date and Time') }} (IST)</th><th>{{ __('Mode') }}</th><th>{{ __('Status') }}</th></tr></thead>
        <tbody>
            @foreach($application->appointments as $i => $appointment)
                <tr class="{{ $i % 2 ? 'alt' : '' }}">
                    <td>{{ $appointment->scheduled_at->ist() }}</td>
                    <td>{{ __($appointment->mode === 'video' ? 'Video' : 'In Person') }}</td>
                    <td>{{ __(ucfirst($appointment->status)) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<h2>{{ __('Application History') }}</h2>
@include('pdf.partials.history')
@endsection
