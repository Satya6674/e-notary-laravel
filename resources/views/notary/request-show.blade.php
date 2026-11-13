@extends('layouts.app')

@section('title', $request->request_number)

@section('content')
@include('partials.certificate-problem', ['application' => $request])
<div class="en-page-head">
    <div><h1>{{ $request->request_number }}</h1><p>@include('partials.status-badge', ['status' => $request->status])</p></div>
    <div class="en-actions">
        <a class="btn btn-outline-primary" href="{{ route('notary.requests') }}">{{ __('Assigned Applications') }}</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header"><h2>{{ __('Application Details') }}</h2></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">{{ __('Citizen') }}</dt><dd class="col-sm-8">{{ $request->user?->name ?? '-' }}</dd>
                    <dt class="col-sm-4">{{ __('Document Type') }}</dt><dd class="col-sm-8">{{ __(ucwords(str_replace('_', ' ', $request->document_type))) }}</dd>
                    @include('partials.application-service', ['application' => $request])
                    <dt class="col-sm-4">{{ __('Purpose') }}</dt><dd class="col-sm-8">{{ $request->purpose }}</dd>
                    @if($request->remarks)<dt class="col-sm-4">{{ __('Notes') }}</dt><dd class="col-sm-8">{{ $request->remarks }}</dd>@endif
                    <dt class="col-sm-4">{{ __('Submitted') }}</dt><dd class="col-sm-8">{{ $request->created_at?->ist('d M Y H:i') }}</dd>
                    @if($request->decision_remarks)<dt class="col-sm-4">{{ __('Decision Remarks') }}</dt><dd class="col-sm-8">{{ $request->decision_remarks }}</dd>@endif
                </dl>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h2>{{ __('Documents') }}</h2></div>
            @include('partials.document-table', ['documents' => $request->documents, 'download' => fn ($doc) => route('notary.request.document.download', [$request->id, $doc])])
        </div>
    </div>

    <div class="col-lg-5">
        @include('partials.application-pdfs', ['application' => $request])
        @include('partials.decision-form', ['title' => 'Your Decision', 'options' => $options, 'routePrefix' => 'notary.request', 'key' => $request->id])

        <div class="card">
            <div class="card-header"><h2>{{ __('Application History') }}</h2></div>
            <div class="card-body">@include('partials.timeline', ['history' => $history, 'staff' => true])</div>
        </div>
    </div>
</div>
@endsection
