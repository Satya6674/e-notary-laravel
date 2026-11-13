{{-- Shown wherever an application reads as completed but a certificate no longer stands. --}}
@if($application->hasCertificateProblem())
    <div class="alert alert-danger" role="alert">{{ __('A certificate on this application is no longer valid, because a stored file is missing or has changed.') }} {{ __('An administrator needs to review this application.') }}</div>
@endif
