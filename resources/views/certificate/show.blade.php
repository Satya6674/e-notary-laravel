@extends('layouts.app')

@section('title', __('Review Certificate'))

@section('content')
<div class="d-flex justify-content-end gap-2 mb-3 en-no-print">
    <a class="btn btn-primary" href="{{ route('applications.certificate-pdf', $application) }}"><i class="fas fa-file-pdf me-2" aria-hidden="true"></i>{{ __('Download') }}</a>
    <button class="btn btn-outline-primary" type="button" onclick="window.print()"><i class="fas fa-print me-2" aria-hidden="true"></i>{{ __('Print or Save as PDF') }}</button>
</div>

@if($withheld)
    <div class="en-notice mb-3 en-no-print" role="status">{{ __('Some certificates on this application are no longer valid and are not shown.') }}</div>
@endif

@forelse($documents as $document)
    <section class="en-certificate mb-4">
        <div class="text-center mb-4">
            <div class="en-brand-mark mx-auto mb-2" style="width:52px;height:52px;"><i class="fas fa-gavel" aria-hidden="true"></i></div>
            <h1 class="h3 mb-1">{{ __('Review Certificate') }}</h1>
            <p class="text-danger fw-bold mb-0">{{ __('NOT LEGALLY VALID. NOT A NOTARISED DOCUMENT.') }}</p>
        </div>

        <div class="row g-4 align-items-center">
            <div class="col-sm-8">
                <dl class="row mb-0">
                    <dt class="col-5">{{ __('Record Number') }}</dt><dd class="col-7">{{ $document->document_number }}</dd>
                    <dt class="col-5">{{ __('Reference') }}</dt><dd class="col-7">{{ $application->request_number }}</dd>
                    <dt class="col-5">{{ __('Citizen') }}</dt><dd class="col-7">{{ $application->user?->name }}</dd>
                    <dt class="col-5">{{ __('Document') }}</dt><dd class="col-7">{{ $document->title }}</dd>
                    <dt class="col-5">{{ __('Document Type') }}</dt><dd class="col-7">{{ __(ucwords(str_replace('_', ' ', $application->document_type))) }}</dd>
                    <dt class="col-5">{{ __('Reviewed By') }}</dt><dd class="col-7">{{ $application->notary?->name ?? __('Administrator') }}</dd>
                    <dt class="col-5">{{ __('Issued') }}</dt><dd class="col-7">{{ $document->certified_at?->ist('d M Y') }}</dd>
                    <dt class="col-5">{{ __('Verification Code') }}</dt><dd class="col-7"><code>{{ $document->verification_code }}</code></dd>
                </dl>
            </div>
            <div class="col-sm-4 text-center">
                <div class="en-qr d-inline-block" data-qr="{{ $document->verificationUrl() }}"></div>
                <small class="d-block text-muted mt-1">{{ __('Scan to check this record') }}</small>
            </div>
        </div>

        <p class="small text-muted mt-4 mb-0">{{ __('This page was produced by E-Notary, an independent software platform. It must not be presented as proof of anything to any person, court, bank or authority.') }}</p>
    </section>
@empty
    <p class="en-notice">{{ __('No certificate records are available for this application.') }}</p>
@endforelse
@endsection

@push('scripts')
<script src="{{ asset('js/qrcode-generator-1.4.4.js') }}"></script>
<script>
    document.querySelectorAll('[data-qr]').forEach(function (el) {
        var qr = qrcode(0, 'M');
        qr.addData(el.dataset.qr);
        qr.make();
        el.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
    });
</script>
@endpush
