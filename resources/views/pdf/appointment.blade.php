@extends('pdf.layout')

@section('body')
@if($appointment->status === 'requested')
    <div class="warn">{{ __('This appointment is not confirmed yet. It is a request only.') }}</div>
@elseif($appointment->status === 'cancelled')
    <div class="warn">{{ __('This appointment was cancelled.') }}</div>
@endif

<h2>{{ __('Appointment Details') }}</h2>
<table class="fields">
    <tr><td class="k">{{ __('Appointment Reference') }}</td><td>{{ $appointment->referenceNumber() }}</td></tr>
    <tr><td class="k">{{ __('Date and Time') }}</td><td>{{ $appointment->scheduled_at->ist() }} IST (UTC+05:30)</td></tr>
    <tr><td class="k">{{ __('Status') }}</td><td>{{ __(ucfirst($appointment->status)) }}</td></tr>
    <tr><td class="k">{{ __('Mode') }}</td><td>{{ __($appointment->mode === 'video' ? 'Video' : 'In Person') }}</td></tr>
    <tr><td class="k">{{ __('Application Reference') }}</td><td>{{ $appointment->request?->request_number ?? '-' }}</td></tr>
    <tr><td class="k">{{ __('Service') }}</td><td>{{ __($appointment->request?->service_details['service'] ?? $report->label($appointment->request?->document_type)) }}</td></tr>
    <tr><td class="k">{{ __('Notary') }}</td><td>{{ $appointment->notary?->name ?? __('Not assigned') }}</td></tr>
    @if($appointment->notes)<tr><td class="k">{{ __('Notes') }}</td><td>{{ $appointment->notes }}</td></tr>@endif
</table>

<h2>{{ __('Instructions') }}</h2>
@if($appointment->mode === 'video')
    <p>{{ __('E-Notary does not provide a video-call link. Your notary will arrange how to connect.') }}</p>
@else
    <p>{{ __('Your notary will tell you where to attend. E-Notary does not store a location for this appointment.') }}</p>
@endif
@if($appointment->isOpen())
    <p>{{ __('You can cancel this appointment from the Appointments page in your portal.') }}</p>
@endif

<div class="note">{{ __('An appointment on E-Notary has no legal effect.') }}</div>
@endsection
