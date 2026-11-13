@extends('layouts.app')

@section('title', __('View Document'))

@section('content')
<x-ui.page-head :title="__('Document Details')">
    <a href="{{ route('admin.documents.index') }}" class="btn btn-outline-primary"><i class="fas fa-arrow-left me-2" aria-hidden="true"></i>{{ __('Back to Documents') }}</a>
</x-ui.page-head>

<x-ui.card class="mb-3">
    <dl class="row mb-0">
        <dt class="col-sm-3">{{ __('Title') }}</dt>
        <dd class="col-sm-9">{{ $document->title ?? __('Untitled') }} @include('partials.document-version')</dd>

        <dt class="col-sm-3">{{ __('Uploaded By') }}</dt>
        <dd class="col-sm-9">{{ optional(optional($document->request)->user)->name ?? __('N/A') }}</dd>

        <dt class="col-sm-3">{{ __('Type') }}</dt>
        <dd class="col-sm-9">{{ __(ucwords(str_replace('_', ' ', $document->request?->document_type ?? 'other'))) }}</dd>

        <dt class="col-sm-3">{{ __('Status') }}</dt>
        <dd class="col-sm-9">@include('partials.status-badge', ['status' => $document->request?->status ?? 'pending'])</dd>

        <dt class="col-sm-3">{{ __('Uploaded') }}</dt>
        <dd class="col-sm-9 mb-0">{{ $document->created_at?->ist('d M Y H:i') }}</dd>
    </dl>
</x-ui.card>

<div class="en-actions">
    @if($document->isPreviewable())<a href="{{ route('documents.preview', $document) }}" target="_blank" rel="noopener" class="btn btn-outline-primary">{{ __('Preview') }}</a>@endif
    <a href="{{ route('admin.documents.download', $document) }}" class="btn btn-outline-success">{{ __('Download') }}</a>
    @unless(in_array($document->request?->status, ['approved', 'completed'], true) || $document->isProtectedRecord())
    <form action="{{ route('admin.documents.destroy', $document) }}" method="POST" data-confirm="{{ __('Remove this document?') }}" data-reason>
        @csrf
        @method('DELETE')
        <button type="submit" class="btn btn-outline-danger">{{ __('Remove') }}</button>
    </form>
    @endunless
</div>
@endsection
