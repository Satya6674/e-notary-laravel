@extends('layouts.app')

@section('title', __('Disclaimer'))

@section('fullwidth', true)

@section('content')
<section class="en-page-hero">
    <div class="en-wrap">
        <h1>{{ __('Disclaimer') }}</h1>
        <p>{{ __('Please read this before using the platform.') }}</p>
    </div>
</section>

<section class="en-section">
    <div class="en-wrap en-narrow en-prose">
        <ul>
            <li>{{ __('E-Notary is an independent software platform. It is not a government service, is not affiliated with any government body, and provides no legally recognised notarisation.') }}</li>
            <li>{{ __('Records and certificates from this platform have no legal effect, and must not be presented as notarised documents.') }}</li>
            <li>{{ __('Document requirements shown on this platform are subject to confirmation. They have not been verified by a legal professional unless a service says otherwise.') }}</li>
            <li>{{ __('Certificate verification confirms only that a record exists on E-Notary.') }}</li>
            <li>{{ __('Uploads are checked for type and size, but not scanned for malware.') }}</li>
        </ul>
        <p>{{ __('See also') }} <a href="{{ route('terms') }}">{{ __('Terms of Service') }}</a> {{ __('and') }} <a href="{{ route('privacy') }}">{{ __('Privacy Policy') }}</a>.</p>
    </div>
</section>
@endsection
