@extends('pdf.layout')

@section('body')
@if($certificateProblem)<div class="warn">{{ __('A certificate on this application is no longer valid, because a stored file is missing or has changed.') }}</div>@endif
<div class="note">{{ __('E-Notary received this application on the date shown below.') }}</div>

<h2>{{ __('Receipt Details') }}</h2>
<table class="fields">
    <tr><td class="k">{{ __('Receipt Reference') }}</td><td>{{ $application->receiptNumber() }}</td></tr>
    <tr><td class="k">{{ __('Application Reference') }}</td><td>{{ $application->request_number }}</td></tr>
    <tr><td class="k">{{ __('Submitted') }}</td><td>{{ $application->created_at?->ist() }} IST</td></tr>
    <tr><td class="k">{{ __('Service') }}</td><td>{{ __($application->service_details['service'] ?? $report->label($application->document_type)) }}</td></tr>
    <tr><td class="k">{{ __('Applicant') }}</td><td>{{ $application->user?->name ?? '-' }}</td></tr>
    <tr><td class="k">{{ __('Current Status') }}</td><td>{{ __($report->label($application->status)) }}</td></tr>
</table>

<h2>{{ __('Documents on Record') }}</h2>
<table class="grid" repeat_header="1">
    <thead><tr><th>{{ __('Document') }}</th><th style="width:28%;">{{ __('Uploaded') }}</th></tr></thead>
    <tbody>
        @forelse($application->documents->whereNull('superseded_at')->values() as $i => $document)
            <tr class="{{ $i % 2 ? 'alt' : '' }}"><td>{{ $document->requirement_key ? __($document->title) : ($document->title ?? __('Untitled')) }}</td><td>{{ $document->created_at?->ist() }} IST</td></tr>
        @empty
            <tr><td colspan="2" class="empty">{{ __('No documents attached.') }}</td></tr>
        @endforelse
    </tbody>
</table>

<h2>{{ __('What This Receipt Means') }}</h2>
<p>{{ __('This receipt confirms only that the application was submitted to E-Notary. It does not show that the application was reviewed or approved.') }}</p>
<div class="warn">{{ __('This receipt is not proof of notarisation, government approval or a completed service.') }}</div>
@endsection
