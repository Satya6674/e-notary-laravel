@extends('layouts.app')

@section('title', __('Appointments'))

@section('content')
<div class="en-page-head"><div><h1>{{ __('Appointments') }}</h1><p>{{ __('Book, confirm and track review sessions.') }}</p></div></div>

@if($bookable->isNotEmpty())
    <div class="card mb-3">
        <div class="card-header"><h2>{{ __('Book an Appointment') }}</h2></div>
        <div class="card-body">
            <form method="POST" action="{{ route('appointments.store') }}" class="row g-3">
                @csrf
                <div class="col-md-4">
                    <label class="form-label" for="request_id">{{ __('Application') }}</label>
                    <select id="request_id" name="request_id" class="form-select" required>
                        @foreach($bookable as $application)
                            <option value="{{ $application->id }}">{{ $application->request_number }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="scheduled_at">{{ __('Date and Time') }}</label>
                    <input id="scheduled_at" type="datetime-local" name="scheduled_at" class="form-control" required value="{{ old('scheduled_at') }}" min="{{ now()->ist('Y-m-d\TH:i') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="mode">{{ __('Mode') }}</label>
                    <select id="mode" name="mode" class="form-select">
                        <option value="video">{{ __('Video') }}</option>
                        <option value="in_person">{{ __('In Person') }}</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="notes">{{ __('Notes') }}</label>
                    <input id="notes" name="notes" class="form-control" maxlength="500" value="{{ old('notes') }}">
                </div>
                <div class="col-12"><button class="btn btn-primary" type="submit">{{ __('Request Appointment') }}</button></div>
            </form>
        </div>
    </div>
@endif

<div class="card">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>{{ __('Date and Time') }}</th><th>{{ __('Application') }}</th><th>{{ __('Citizen') }}</th><th>{{ __('Notary') }}</th><th>{{ __('Mode') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
            <tbody>
                @forelse($appointments as $appointment)
                    @php
                        $viewer = auth()->user();
                        $staff = $viewer->isAdmin() || $viewer->id === (int) $appointment->notary_id;
                    @endphp
                    <tr>
                        <td class="text-nowrap">{{ $appointment->scheduled_at->ist('d M Y H:i') }}</td>
                        <td>{{ $appointment->request?->request_number }}</td>
                        <td>{{ $appointment->user?->name }}</td>
                        <td>{{ $appointment->notary?->name ?? __('Not assigned') }}</td>
                        <td>{{ __($appointment->mode === 'video' ? 'Video' : 'In Person') }}</td>
                        <td><span class="en-badge en-badge-{{ ['requested' => 'pending', 'confirmed' => 'assigned', 'completed' => 'approved', 'cancelled' => 'rejected'][$appointment->status] }}">{{ __(ucfirst($appointment->status)) }}</span></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('appointments.pdf', $appointment) }}"><i class="fas fa-file-pdf" aria-hidden="true"></i><span class="visually-hidden">{{ __('Download') }}</span></a>
                            @if($appointment->isOpen())
                                @if($staff && $appointment->status === 'requested')
                                    <form class="d-inline" method="POST" action="{{ route('appointments.update', $appointment) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="confirmed"><button class="btn btn-sm btn-primary" type="submit">{{ __('Confirm') }}</button></form>
                                @endif
                                @if($staff && $appointment->status === 'confirmed')
                                    <form class="d-inline" method="POST" action="{{ route('appointments.update', $appointment) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="completed"><button class="btn btn-sm btn-primary" type="submit">{{ __('Complete') }}</button></form>
                                @endif
                                <form class="d-inline" method="POST" action="{{ route('appointments.update', $appointment) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="cancelled"><button class="btn btn-sm btn-outline-danger" type="submit">{{ __('Cancel') }}</button></form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">{{ __('No appointments yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $appointments->links() }}</div>
@endsection
