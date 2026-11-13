@extends('layouts.app')

@section('title', $request->request_number)

@section('content')
@include('partials.certificate-problem', ['application' => $request])
<div class="en-page-head">
    <div><h1>{{ $request->request_number }}</h1><p>@include('partials.status-badge', ['status' => $request->status])</p></div>
    <div class="en-actions">
        @if($request->status === 'completed' && $request->hasValidCertificate())
            <a class="btn btn-gold" href="{{ route('applications.certificate', $request) }}"><i class="fas fa-award me-2" aria-hidden="true"></i>{{ __('Review Certificate') }}</a>
        @endif
        @if($request->canBeWithdrawn())
            <form method="POST" action="{{ route('user.requests.withdraw', $request) }}" data-confirm="{{ __('Withdraw this application? Its files will be deleted.') }}">
                @csrf
                <button type="submit" class="btn btn-outline-danger">{{ __('Withdraw Application') }}</button>
            </form>
        @endif
        <a class="btn btn-outline-primary" href="{{ route('user.requests') }}">{{ __('Back to Applications') }}</a>
    </div>
</div>

@if($request->status === 'correction_requested')
    <div class="card mb-3" style="border-color:var(--en-gold);">
        <div class="card-header"><h2>{{ __('Correction Needed') }}</h2></div>
        <div class="card-body">
            <p>{{ $request->decision_remarks }}</p>
            <form method="POST" action="{{ route('user.requests.resubmit', $request) }}" enctype="multipart/form-data">
                @csrf
                <div class="mb-3">
                    <label class="form-label" for="note">{{ __('What did you change?') }}</label>
                    <textarea id="note" name="note" class="form-control" rows="2" required minlength="3" maxlength="1000">{{ old('note') }}</textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="file">{{ __('Corrected file (optional)') }}</label>
                    <input id="file" type="file" name="file" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                </div>
                @php($currentDocuments = $request->documents->whereNull('superseded_at'))
                @if($currentDocuments->isNotEmpty())
                    <div class="mb-3">
                        <label class="form-label" for="replaces">{{ __('Which document does this file replace?') }}</label>
                        <select id="replaces" name="replaces" class="form-select @error('replaces') is-invalid @enderror">
                            @if($currentDocuments->count() > 1)<option value="">{{ __('Choose a document') }}</option>@endif
                            @foreach($currentDocuments as $doc)
                                <option value="{{ $doc->id }}" @selected((int) old('replaces') === $doc->id)>{{ $doc->requirement_key ? __($doc->title) : ($doc->title ?? __('Untitled')) }}</option>
                            @endforeach
                            <option value="new" @selected(old('replaces') === 'new')>{{ __('Add as a new document') }}</option>
                        </select>
                        @error('replaces')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                @endif
                <button class="btn btn-primary" type="submit">{{ __('Send Correction') }}</button>
            </form>
        </div>
    </div>
@endif

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header"><h2>{{ __('Application Details') }}</h2></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">{{ __('Document Type') }}</dt><dd class="col-sm-8">{{ __(ucwords(str_replace('_', ' ', $request->document_type))) }}</dd>
                    @include('partials.application-service', ['application' => $request])
                    <dt class="col-sm-4">{{ __('Purpose') }}</dt><dd class="col-sm-8">{{ $request->purpose }}</dd>
                    @if($request->remarks)<dt class="col-sm-4">{{ __('Notes') }}</dt><dd class="col-sm-8">{{ $request->remarks }}</dd>@endif
                    <dt class="col-sm-4">{{ __('Assigned Notary') }}</dt><dd class="col-sm-8">{{ $request->notary?->name ?? __('Not assigned') }}</dd>
                    <dt class="col-sm-4">{{ __('Submitted') }}</dt><dd class="col-sm-8">{{ $request->created_at?->ist('d M Y H:i') }}</dd>
                    @if($request->decision_remarks && $request->status !== 'correction_requested')
                        <dt class="col-sm-4">{{ __('Decision Remarks') }}</dt><dd class="col-sm-8">{{ $request->decision_remarks }}</dd>
                    @endif
                </dl>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h2>{{ __('Documents') }}</h2></div>
            @include('partials.document-table', ['documents' => $request->documents, 'download' => fn ($doc) => route('user.documents.download', $doc)])
        </div>
    </div>
    <div class="col-lg-5">
        @include('partials.application-pdfs', ['application' => $request])
        <div class="card">
            <div class="card-header"><h2>{{ __('Application History') }}</h2></div>
            <div class="card-body">@include('partials.timeline', ['history' => $history, 'staff' => false])</div>
        </div>
    </div>
</div>
@endsection
