@extends('layouts.app')

@section('title', __('Terms of Use'))

@section('content')
<article class="en-prose">
    <div class="en-page-head"><div><h1>{{ __('Terms of Use') }}</h1><p>{{ __('Version') }}: {{ config('privacy.terms_version') }}</p></div></div>
    <div class="card"><div class="card-body">
        <div class="en-demo-stamp mb-3">{{ __('Nothing produced by this site has legal effect.') }}</div>

        <h2>{{ __('What this site is') }}</h2>
        <p>{{ __('E-Notary is an independent software platform for managing a document review workflow. By using it you accept these terms.') }}</p>

        <h2>{{ __('What this site is not') }}</h2>
        <ul>
            <li>{{ __('It is not a government service and is not endorsed by any government body.') }}</li>
            <li>{{ __('It does not provide legally recognised notarisation, legal advice or any regulated service.') }}</li>
            <li>{{ __('An account labelled notary is a role on this platform. It does not indicate a licensed notary.') }}</li>
            <li>{{ __('It does not check identity, and it does not apply a legally recognised signature, seal or stamp.') }}</li>
        </ul>

        <h2>{{ __('Review certificates') }}</h2>
        <p>{{ __('Certificates, verification codes and QR codes from this site are platform records only. They must not be presented to any person, court, bank or authority as proof of anything. Presenting one as a genuine notarised document may be unlawful.') }}</p>

        <h2>{{ __('Acceptable use') }}</h2>
        <ul>
            <li>{{ __('Use sample documents and sample details only. Do not upload real identity or legal documents.') }}</li>
            <li>{{ __('Do not upload anything unlawful, harmful, or belonging to someone else.') }}</li>
            <li>{{ __('Do not try to bypass security, reach other people\'s data, or disrupt the site.') }}</li>
            <li>{{ __('Keep your password private. You are responsible for activity on your account.') }}</li>
        </ul>

        <h2>{{ __('No guarantee') }}</h2>
        <p>{{ __('The site is provided as it is. It may change, stop working or be withdrawn at any time, and data may be deleted without notice. To the extent the law allows, the platform owner is not liable for any loss arising from its use.') }}</p>

        <h2>{{ __('Accounts') }}</h2>
        <p>{{ __('We may suspend or remove any account or content, particularly where these terms are not followed.') }}</p>

        <h2>{{ __('Changes') }}</h2>
        <p>{{ __('These terms may be updated. The date at the top of this page shows the latest version.') }}</p>
    </div></div>
</article>
@endsection
