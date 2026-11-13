@extends('pdf.layout')

@section('body')
@if($certificateProblem)<div class="warn">{{ __('A certificate on this application is no longer valid, because a stored file is missing or has changed.') }}</div>@endif
<h2>{{ __('Review Status') }}</h2>
<table class="fields">
    <tr><td class="k">{{ __('Application Reference') }}</td><td>{{ $application->request_number }}</td></tr>
    <tr><td class="k">{{ __('Service') }}</td><td>{{ __($application->service_details['service'] ?? $report->label($application->document_type)) }}</td></tr>
    <tr><td class="k">{{ __('Current Status') }}</td><td>{{ __($report->label($application->status)) }}</td></tr>
    <tr><td class="k">{{ __('Reviewer') }}</td><td>{{ $application->notary ? ($staff ? $application->notary->name : __('Assigned notary')) : __('Not assigned') }}</td></tr>
    <tr><td class="k">{{ __('Last Decision') }}</td><td>{{ $application->decided_at ? $application->decided_at->ist().' IST' : __('None recorded') }}</td></tr>
    @if($application->decision_remarks)<tr><td class="k">{{ __('Decision Remarks') }}</td><td>{{ $application->decision_remarks }}</td></tr>@endif
    <tr><td class="k">{{ __('Next Action') }}</td><td>{{ __($nextAction) }}</td></tr>
</table>

<h2>{{ __('Documents Under Review') }}</h2>
<p class="muted">{{ __('Decisions apply to the whole application, not to individual documents.') }}</p>
<table class="grid" repeat_header="1">
    <thead><tr><th>{{ __('Document') }}</th><th style="width:30%;">{{ __('Application Status') }}</th><th style="width:24%;">{{ __('Uploaded') }}</th></tr></thead>
    <tbody>
        @forelse($application->documents->whereNull('superseded_at')->values() as $i => $document)
            <tr class="{{ $i % 2 ? 'alt' : '' }}">
                <td>{{ $document->requirement_key ? __($document->title) : ($document->title ?? __('Untitled')) }}</td>
                <td>{{ __($report->label($application->status)) }}</td>
                <td>{{ $document->created_at?->ist() }}</td>
            </tr>
        @empty
            <tr><td colspan="3" class="empty">{{ __('No documents attached.') }}</td></tr>
        @endforelse
    </tbody>
</table>

<h2>{{ __('Review History') }}</h2>
@include('pdf.partials.history')

<div class="note">{{ __('This report records actions on the E-Notary platform. It has no legal effect.') }}</div>
@endsection
