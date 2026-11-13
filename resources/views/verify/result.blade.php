@extends('layouts.app')

@section('title', __('Verification Result'))

@section('content')
@php
    $states = [
        'valid'       => ['fa-circle-check', 'text-success', 'This record exists and is current.'],
        'revoked'     => ['fa-circle-xmark', 'text-danger', 'This record has been revoked.'],
        'superseded'  => ['fa-circle-minus', 'en-text-caution', 'This record was replaced by a newer version and is no longer current.'],
        'unverifiable' => ['fa-circle-xmark', 'text-danger', 'This record cannot be confirmed, because the stored file is missing or does not match its fingerprint.'],
        'unavailable' => ['fa-circle-minus', 'en-text-caution', 'This record is no longer available.'],
        'invalid'     => ['fa-circle-xmark', 'text-danger', 'No record matches this code.'],
    ];
    [$icon, $tone, $message] = $states[$state];
@endphp

<div class="en-verify-card">
    <x-ui.page-head :title="__('Verification Result')" />

    <x-ui.card>
        <p class="h5 {{ $tone }} mb-3" role="status"><i class="fas {{ $icon }} me-2" aria-hidden="true"></i>{{ __($message) }}</p>
        <div class="en-notice mb-3">{{ __('This record has no legal effect.') }} {{ __('E-Notary does not provide legally recognised notarisation.') }}</div>

        @if($document && $state !== 'unavailable')
            <dl class="row mb-0">
                <dt class="col-sm-4">{{ __('Record Number') }}</dt><dd class="col-sm-8">{{ $document->document_number }}</dd>
                <dt class="col-sm-4">{{ __('Reference') }}</dt><dd class="col-sm-8">{{ $document->request->request_number }}</dd>
                <dt class="col-sm-4">{{ __('Document Type') }}</dt><dd class="col-sm-8">{{ __(ucwords(str_replace('_', ' ', $document->request->document_type))) }}</dd>
                @if($state === 'valid')
                    <dt class="col-sm-4">{{ __('Status') }}</dt><dd class="col-sm-8">@include('partials.status-badge', ['status' => $document->request->status])</dd>
                @endif
                <dt class="col-sm-4">{{ __('Version') }}</dt><dd class="col-sm-8">{{ $document->version }}</dd>
                <dt class="col-sm-4">{{ __('Issued') }}</dt><dd class="col-sm-8">{{ $document->certified_at?->ist('d M Y') }}</dd>
                @if($document->file_hash && $state === 'valid')
                    <dt class="col-sm-4">{{ __('File Fingerprint') }}</dt><dd class="col-sm-8 text-break"><code>{{ $document->file_hash }}</code></dd>
                @endif
            </dl>
        @endif

        <a class="btn btn-outline-primary mt-3" href="{{ route('verify.index') }}">{{ __('Check Another Code') }}</a>
    </x-ui.card>
</div>
@endsection
