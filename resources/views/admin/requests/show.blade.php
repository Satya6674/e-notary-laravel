@extends('layouts.app')

@section('title', $requestItem->request_number)

@section('content')
@include('partials.certificate-problem', ['application' => $requestItem])
@if($requestItem->notary && $requestItem->notary->isDeactivated() && ! $requestItem->isFinal())
    {{-- Nothing is moved automatically: the administrator chooses who takes the work. --}}
    <div class="alert alert-warning" role="status">{{ __('The Notary assigned to this application is deactivated and cannot work on it. Reassign it, or decide it yourself.') }}</div>
@endif
<div class="en-page-head">
    <div><h1>{{ $requestItem->request_number }}</h1><p>@include('partials.status-badge', ['status' => $requestItem->status])</p></div>
    <div class="en-actions">
        @if($requestItem->status === 'completed' && $requestItem->hasValidCertificate())
            <a class="btn btn-gold" href="{{ route('applications.certificate', $requestItem) }}">{{ __('Review Certificate') }}</a>
        @endif
        <a class="btn btn-outline-primary" href="{{ route('admin.requests.index') }}">{{ __('Applications') }}</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header"><h2>{{ __('Application Details') }}</h2></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">{{ __('Citizen') }}</dt>
                    <dd class="col-sm-8">{{ $requestItem->user?->name ?? '-' }} <span class="text-muted small">{{ $requestItem->user?->contactEmail() }}</span></dd>
                    <dt class="col-sm-4">{{ __('Document Type') }}</dt><dd class="col-sm-8">{{ __(ucwords(str_replace('_', ' ', $requestItem->document_type))) }}</dd>
                    @include('partials.application-service', ['application' => $requestItem])
                    <dt class="col-sm-4">{{ __('Assigned Notary') }}</dt><dd class="col-sm-8">{{ $requestItem->notary?->name ?? __('Not assigned') }}</dd>
                    <dt class="col-sm-4">{{ __('Purpose') }}</dt><dd class="col-sm-8">{{ $requestItem->purpose }}</dd>
                    @if($requestItem->remarks)<dt class="col-sm-4">{{ __('Notes') }}</dt><dd class="col-sm-8">{{ $requestItem->remarks }}</dd>@endif
                    @if($requestItem->decision_remarks)<dt class="col-sm-4">{{ __('Decision Remarks') }}</dt><dd class="col-sm-8">{{ $requestItem->decision_remarks }}</dd>@endif
                    <dt class="col-sm-4">{{ __('Submitted') }}</dt><dd class="col-sm-8">{{ $requestItem->created_at?->ist('d M Y H:i') }}</dd>
                    @if($requestItem->decided_at)<dt class="col-sm-4">{{ __('Decided') }}</dt><dd class="col-sm-8">{{ $requestItem->decided_at->ist('d M Y H:i') }}</dd>@endif
                </dl>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h2>{{ __('Documents') }}</h2></div>
            @include('partials.document-table', ['documents' => $requestItem->documents, 'download' => fn ($doc) => route('admin.documents.download', $doc)])
        </div>
    </div>

    <div class="col-lg-5">
        @include('partials.application-pdfs', ['application' => $requestItem])
        @if(in_array($requestItem->status, ['pending', 'assigned']))
            <div class="card mb-3">
                <div class="card-header"><h2>{{ __('Assign to a Notary') }}</h2></div>
                <div class="card-body">
                    @if($notaries->isEmpty())
                        <p class="text-muted small mb-0">{{ __('No approved notaries yet.') }} <a href="{{ route('admin.notaries.index') }}">{{ __('Notaries') }}</a></p>
                    @else
                        <form method="POST" action="{{ route('admin.requests.assign', $requestItem) }}">
                            @csrf
                            {{-- What this page showed, so a change by another administrator is not overwritten. --}}
                            <input type="hidden" name="expected_notary_id" value="{{ $requestItem->notary_id }}">
                            <select name="notary_id" class="form-select mb-2" required aria-label="{{ __('Notary') }}">
                                <option value="">{{ __('Choose a notary') }}</option>
                                @foreach($notaries as $n)
                                    <option value="{{ $n->id }}" @selected((int) $requestItem->notary_id === $n->id)>{{ $n->name }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn btn-primary w-100">{{ __('Assign') }}</button>
                        </form>
                    @endif
                </div>
            </div>
        @endif

        @include('partials.decision-form', [
            'title' => 'Admin Decision',
            'options' => $options,
            'routePrefix' => 'admin.requests',
            'key' => $requestItem,
            'warning' => in_array($requestItem->status, \App\Models\NotaryRequest::DECIDED_STATUSES, true)
                ? 'This application already has a decision. Your decision will be recorded as an override.' : null,
        ])

        <div class="card">
            <div class="card-header"><h2>{{ __('Application History') }}</h2></div>
            <div class="card-body">@include('partials.timeline', ['history' => $history, 'staff' => true])</div>
        </div>
    </div>
</div>
@endsection
