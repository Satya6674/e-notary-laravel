@extends('layouts.app')

@section('title', __('Secure document review, from submission to decision'))

@section('fullwidth', true)

@section('content')
@php($supportEmail = \App\Models\Setting::get('support_email'))
@php($featuredServices = app(\App\Services\ServiceCatalogue::class)->featured())

<section class="en-hero">
    <div class="en-wrap">
        <div>
            <span class="en-eyebrow">{{ __('Independent Software Platform') }}</span>
            <h1>{{ __('Document review,') }} <em>{{ __('from submission to decision.') }}</em></h1>
            <p class="lead">{{ __('E-Notary gives citizens, notaries and administrators one secure place to submit documents, follow every step and maintain an organized application record.') }}</p>
            <div class="en-hero-actions">
                <a class="btn btn-primary btn-lg" href="{{ route('register') }}">{{ __('Create Account') }}</a>
                <a class="btn btn-outline-light btn-lg" href="{{ route('verify.index') }}"><i class="fas fa-qrcode me-2" aria-hidden="true"></i>{{ __('Verify a Certificate') }}</a>
            </div>
            <ul class="en-hero-points">
                @foreach([
                    ['fa-lock', 'Secure Submission', 'Private file storage'],
                    ['fa-route', 'Track Application', 'Every step recorded'],
                    ['fa-user-tie', 'Connect with Notaries', 'Assigned review'],
                    ['fa-folder-open', 'Complete Records', 'Full history kept'],
                ] as [$icon, $title, $text])
                    <li><i class="fas {{ $icon }}" aria-hidden="true"></i><span><strong>{{ __($title) }}</strong>{{ __($text) }}</span></li>
                @endforeach
            </ul>
        </div>
        <div class="en-hero-visual">
            <span class="en-sheet" aria-hidden="true"></span>
            <span class="en-sheet is-back" aria-hidden="true"></span>
            <figure class="en-track">
                <div class="en-track-head">
                    <strong>{{ __('Application tracking') }}</strong>
                    <span class="en-meta">{{ __('Illustrative example') }}</span>
                </div>
                <ol>
                    @foreach([
                        ['is-done', 'fa-circle-check', 'Submitted', 'Your application and documents are received.'],
                        ['is-done', 'fa-circle-check', 'Assigned to a Notary', 'An administrator assigns a notary.'],
                        ['is-now', 'fa-circle-dot', 'Under Review', 'The notary reviews your documents.'],
                        ['is-next', 'fa-circle', 'Decision Recorded', 'The decision and its reason are saved.'],
                    ] as [$state, $icon, $title, $text])
                        <li class="{{ $state }}"><i class="fas {{ $icon }}" aria-hidden="true"></i><span><strong>{{ __($title) }}</strong>{{ __($text) }}</span></li>
                    @endforeach
                </ol>
            </figure>
        </div>
    </div>
</section>

<section class="en-strip" id="features" aria-label="{{ __('Platform Features') }}">
    <div class="en-wrap">
        @foreach([
            ['fa-shield-halved', 'Secure Document Submission', 'Files are kept outside the public web folder.'],
            ['fa-list-check', 'Application Tracking', 'See the status and history of each application.'],
            ['fa-stamp', 'Notary Review Workflow', 'An assigned notary records each decision with a reason.'],
            ['fa-box-archive', 'Organized Document Records', 'Documents stay with their application.'],
        ] as [$icon, $title, $text])
            <div><i class="fas {{ $icon }}" aria-hidden="true"></i><span><strong>{{ __($title) }}</strong>{{ __($text) }}</span></div>
        @endforeach
    </div>
</section>

<section class="en-section" id="services">
    <div class="en-wrap">
        <div class="en-section-head">
            <span class="en-eyebrow">{{ __('Our Services') }}</span>
            <h2>{{ __('Popular Document Services') }}</h2>
            <p>{{ __('Explore document services designed to help individuals and businesses organize their documentation requests.') }}</p>
        </div>
        <div class="en-svc-grid">
            @foreach($featuredServices as $service)
                @include('partials.service-card')
            @endforeach
        </div>
        <div class="text-center mt-4">
            <a class="btn btn-outline-primary btn-lg" href="{{ route('services.index') }}">{{ __('View All Services') }}</a>
        </div>
    </div>
</section>

<section class="en-section en-section-alt" id="how-it-works">
    <div class="en-wrap">
        <div class="en-section-head">
            <span class="en-eyebrow">{{ __('How It Works') }}</span>
            <h2>{{ __('Four clear steps') }}</h2>
        </div>
        <ol class="en-flow">
            @foreach([
                ['Choose Your Service', 'Browse services and see the documents each one needs.'],
                ['Submit Application Details', 'Enter the details for your service and state the purpose.'],
                ['Upload Required Documents', 'Add each document, then review everything before you submit.'],
                ['Track Application Progress', 'Follow each status, respond to corrections and see the decision.'],
            ] as [$title, $text])
                <li><strong>{{ __($title) }}</strong><span>{{ __($text) }}</span></li>
            @endforeach
        </ol>
    </div>
</section>

<section class="en-section" id="why">
    <div class="en-wrap">
        <div class="en-section-head">
            <span class="en-eyebrow">{{ __('Why Choose E-Notary') }}</span>
            <h2>{{ __('Built around the review process') }}</h2>
        </div>
        <div class="en-grid">
            @foreach([
                ['fa-folder-tree', 'Organized Application Management', 'Each application keeps its details, documents and decisions together.'],
                ['fa-file-pdf', 'Document Upload and Preview', 'Upload PDF, JPG, PNG, DOC or DOCX files. PDF and image files can be previewed; Word files can be downloaded.'],
                ['fa-route', 'Application Status Tracking', 'See the current status of each application at any time.'],
                ['fa-user-lock', 'Role-Based Access', 'Citizens, notaries and administrators each see only what their role allows.'],
                ['fa-stamp', 'Notary Review Workflow', 'Notaries approve, reject or request a correction, and must record a reason.'],
                ['fa-clock-rotate-left', 'Application History', 'Each decision is logged with its time, its reason and who made it.'],
            ] as [$icon, $title, $text])
                <article class="en-feature">
                    <i class="fas {{ $icon }}" aria-hidden="true"></i>
                    <h3>{{ __($title) }}</h3>
                    <p>{{ __($text) }}</p>
                </article>
            @endforeach
        </div>
    </div>
</section>

<section class="en-verify-band">
    <div class="en-wrap">
        <div>
            <span class="en-eyebrow">{{ __('Verify') }}</span>
            <h2>{{ __('Check a certificate') }}</h2>
            <p>{{ __('This check confirms that a record exists on E-Notary. It has no legal effect.') }}</p>
        </div>
        <form method="GET" action="{{ route('verify.index') }}" class="en-verify-form">
            <label class="form-label" for="home-code">{{ __('Verification Code') }}</label>
            <div>
                <input id="home-code" name="code" class="form-control" maxlength="16" required autocomplete="off">
                <button class="btn btn-primary" type="submit">{{ __('Verify') }}</button>
            </div>
        </form>
    </div>
</section>

<section class="en-section en-section-alt" id="faq">
    <div class="en-wrap" style="max-width:820px;">
        <div class="en-section-head">
            <span class="en-eyebrow">{{ __('FAQs') }}</span>
            <h2>{{ __('Common questions') }}</h2>
        </div>
        <div class="accordion" id="faqList">
            @foreach([
                ['Is E-Notary a government service?', 'No. E-Notary is an independent software platform and is not affiliated with any government body.'],
                ['Does E-Notary provide legally recognised notarisation?', 'No. Records and certificates from this platform have no legal effect, and must not be presented as notarised documents.'],
                ['Who can see my documents?', 'Only you, the notary assigned to your application, and administrators. Files are never available at a public address.'],
                ['Can I use it for real identity or legal documents?', 'Please do not. Use sample documents and sample details only.'],
                ['What does certificate verification show?', 'It shows a reference, the document type, the status and the date. It does not show names, contact details or the document.'],
                ['Are my documents scanned for viruses?', 'No. Uploads are checked for file type and size, but not scanned for malware.'],
            ] as $i => [$question, $answer])
                <div class="accordion-item">
                    <h3 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq{{ $i }}" aria-expanded="false" aria-controls="faq{{ $i }}">{{ __($question) }}</button>
                    </h3>
                    <div id="faq{{ $i }}" class="accordion-collapse collapse" data-bs-parent="#faqList">
                        <div class="accordion-body">{{ __($answer) }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>

<section class="en-section" id="contact">
    <div class="en-wrap" style="max-width:820px;">
        <div class="en-section-head">
            <span class="en-eyebrow">{{ __('Contact') }}</span>
            <h2>{{ __('Send us a message') }}</h2>
            <p>{{ __('Messages go to the platform administrator. Please do not include personal or sensitive details.') }}
                @if($supportEmail) {{ __('Support Email') }}: {{ $supportEmail }}@endif</p>
        </div>
        <x-ui.card>
            @if(session('contact_status'))
                <div class="alert alert-success" role="status">{{ __(session('contact_status')) }}</div>
            @endif
            <form method="POST" action="{{ route('contact.store') }}" id="contactForm">
                @csrf
                <input type="hidden" name="_contact_form" value="1">
                {{-- Honeypot: hidden from people, filled by bots; the server ignores such posts. --}}
                <div style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true">
                    <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                </div>
                <div class="row">
                    <div class="col-sm-6"><x-ui.field name="name" :label="__('Full Name')" required maxlength="120" autocomplete="name" /></div>
                    <div class="col-sm-6"><x-ui.field name="email" type="email" :label="__('Email Address')" required autocomplete="email" /></div>
                    <div class="col-sm-6"><x-ui.field name="mobile" type="tel" :label="__('Mobile Number')" maxlength="20" autocomplete="tel" /></div>
                    <div class="col-sm-6 mb-3">
                        <label class="form-label" for="f-subject">{{ __('Subject') }} <span class="en-required" aria-hidden="true">*</span></label>
                        <select id="f-subject" name="subject" class="form-select @error('subject') is-invalid @enderror" required>
                            <option value="">{{ __('Select subject') }}</option>
                            @foreach(\App\Http\Controllers\Contact\ContactController::SUBJECTS as $subject)
                                <option value="{{ $subject }}" @selected(old('subject') === $subject)>{{ __($subject) }}</option>
                            @endforeach
                        </select>
                        @error('subject')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 mb-3">
                        <label class="form-label" for="f-message">{{ __('Message') }} <span class="en-required" aria-hidden="true">*</span></label>
                        <textarea id="f-message" name="message" class="form-control @error('message') is-invalid @enderror" rows="4" required minlength="10" maxlength="2000">{{ old('message') }}</textarea>
                        @error('message')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">{{ __('Send Message') }}</button>
            </form>
        </x-ui.card>
    </div>
</section>
@endsection
