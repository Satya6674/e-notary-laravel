{{-- Generated documents for an application, for anyone who can see this page. --}}
<div class="card mb-3 en-no-print">
    <div class="card-header"><h2>{{ __('Application Documents') }}</h2></div>
    <ul class="en-pdf-list">
        @foreach(array_filter([
            'applications.summary' => 'Application Summary',
            'applications.receipt' => 'Application Submission Receipt',
            'applications.review-report' => 'Document Review Report',
            'applications.certificate-pdf' => $application->status === 'completed' && $application->hasValidCertificate() ? 'Review Certificate' : null,
        ]) as $route => $label)
            <li>
                <span><i class="fas fa-file-pdf me-2" aria-hidden="true"></i>{{ __($label) }}</span>
                <span class="text-nowrap">
                    <a class="btn btn-sm btn-outline-primary" href="{{ route($route, [$application, 'preview' => 1]) }}" target="_blank" rel="noopener">{{ __('Preview') }}<span class="visually-hidden">: {{ __($label) }}</span></a>
                    <a class="btn btn-sm btn-primary" href="{{ route($route, $application) }}">{{ __('Download') }}<span class="visually-hidden">: {{ __($label) }}</span></a>
                </span>
            </li>
        @endforeach
    </ul>
</div>
