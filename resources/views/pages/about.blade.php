@extends('layouts.app')

@section('title', __('About E-Notary'))

@section('fullwidth', true)

@section('content')
<section class="en-page-hero">
    <div class="en-wrap">
        <span class="en-eyebrow">{{ __('About') }}</span>
        <h1>{{ __('About E-Notary') }}</h1>
        <p>{{ __('An independent software platform for managing document review.') }}</p>
    </div>
</section>

<section class="en-section">
    <div class="en-wrap en-narrow en-prose">
        <h2>{{ __('What the platform does') }}</h2>
        <p>{{ __('E-Notary gives citizens, notaries and administrators one place to handle a document review. Citizens choose a service, enter their details and upload documents. An administrator assigns a notary, who reviews the application and records a decision with a reason.') }}</p>
        <h2>{{ __('What it does not do') }}</h2>
        <p>{{ __('E-Notary is not a government service and is not affiliated with any government body. Records and certificates from this platform have no legal effect, and must not be presented as notarised documents.') }}</p>
        <h2>{{ __('How your documents are handled') }}</h2>
        <p>{{ __('Files are kept outside the public web folder. Only you, your assigned notary and administrators can open them. Uploads are checked for type and size, but not scanned for malware.') }}</p>
        <div class="en-hero-actions">
            <a class="btn btn-primary" href="{{ route('services.index') }}">{{ __('View All Services') }}</a>
            <a class="btn btn-outline-primary" href="{{ route('home') }}#contact">{{ __('Contact Us') }}</a>
        </div>
    </div>
</section>
@endsection
