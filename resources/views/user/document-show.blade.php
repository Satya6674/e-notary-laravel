@extends('layouts.app')

@section('title', __('View Document'))

@section('content')
<x-ui.page-head :title="__('Document Details')">
    <a href="{{ route('user.documents') }}" class="btn btn-outline-primary"><i class="fas fa-arrow-left me-2" aria-hidden="true"></i>{{ __('Back to Documents') }}</a>
</x-ui.page-head>

<x-ui.card class="mb-3">
    <dl class="row mb-0">
        <dt class="col-sm-3">{{ __('Document Title') }}</dt>
        <dd class="col-sm-9">{{ $document->title ?? optional($document->request)->purpose ?? '-' }} @include('partials.document-version')</dd>

        <dt class="col-sm-3">{{ __('Request ID') }}</dt>
        <dd class="col-sm-9">{{ optional($document->request)->request_number ?? '-' }}</dd>

        <dt class="col-sm-3">{{ __('Type') }}</dt>
        <dd class="col-sm-9">{{ __(ucwords(str_replace('_', ' ', $document->request?->document_type ?? 'other'))) }}</dd>

        <dt class="col-sm-3">{{ __('Status') }}</dt>
        <dd class="col-sm-9">@include('partials.status-badge', ['status' => $document->request?->status ?? 'pending'])</dd>

        <dt class="col-sm-3">{{ __('Description') }}</dt>
        <dd class="col-sm-9">{{ optional($document->request)->purpose ?? '-' }}</dd>

        @if(optional($document->request)->remarks)
            <dt class="col-sm-3">{{ __('Notes') }}</dt>
            <dd class="col-sm-9">{{ $document->request->remarks }}</dd>
        @endif

        <dt class="col-sm-3">{{ __('Uploaded') }}</dt>
        <dd class="col-sm-9 mb-0">{{ $document->created_at?->ist('d M Y H:i') }}</dd>
    </dl>
</x-ui.card>

<div class="en-actions">
    @if($document->isPreviewable())<a href="{{ route('documents.preview', $document) }}" target="_blank" rel="noopener" class="btn btn-outline-primary">{{ __('Preview') }}</a>@endif
    <a href="{{ route('user.documents.download', $document) }}" class="btn btn-primary">{{ __('Download File') }}</a>
</div>
@endsection
