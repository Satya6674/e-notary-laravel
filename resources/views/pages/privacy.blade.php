@extends('layouts.app')

@section('title', __('Privacy Policy'))

@section('content')
<article class="en-prose">
    <div class="en-page-head"><div><h1>{{ __('Privacy Policy') }}</h1><p>{{ __('Version') }}: {{ config('privacy.privacy_version') }}</p></div></div>
    <div class="card"><div class="card-body">
        <div class="en-demo-stamp mb-3">{{ __('Please use sample details and sample documents only.') }}</div>

        <h2>{{ __('Who runs this site') }}</h2>
        <p>{{ __('E-Notary is an independent software platform. It is not a government service and is not connected to any government body, court or notary authority.') }}</p>

        <h2>{{ __('What we store') }}</h2>
        <ul>
            <li>{{ __('Account details: name, email address, mobile number and role.') }}</li>
            <li>{{ __('A hashed version of your password. We never store the password itself.') }}</li>
            <li>{{ __('Anything you submit: applications, uploaded documents, a profile photo, appointments, support tickets and contact messages.') }}</li>
            <li>{{ __('An activity log: key actions, with the time, IP address and browser details.') }}</li>
            <li>{{ __('One-time codes, stored only in hashed form, and removed when no longer needed.') }}</li>
        </ul>

        <h2>{{ __('How we use it') }}</h2>
        <p>{{ __('We use this information only to run the platform: to sign you in, move applications through review, send service messages, protect accounts and investigate misuse. We do not sell it or use it for advertising.') }}</p>

        <h2>{{ __('Who can see it') }}</h2>
        <ul>
            <li>{{ __('Your uploaded documents are held in private storage. Only you, the notary assigned to your application and site administrators can open them.') }}</li>
            <li>{{ __('The public verification page shows a reference, document type, status and dates. It does not show your name, contact details or the document.') }}</li>
        </ul>

        <h2>{{ __('Service providers') }}</h2>
        <p>{{ __('When message sending is switched on, email is delivered through Google (Gmail) and text messages through MSG91. They receive only the address or number and the message.') }}</p>

        <h2>{{ __('Cookies') }}</h2>
        <p>{{ __('We use only the cookies needed for sign-in, security and your language choice. We do not use advertising or analytics cookies.') }}</p>

        <h2>{{ __('How long we keep it') }}</h2>
        <p>{{ __('Removed documents are kept for a retention period and then permanently deleted. Applications and activity records may be kept longer as part of the record. Data may also be removed without notice, so do not rely on this platform to keep any document.') }}</p>
        <table class="table table-sm">
            <caption class="visually-hidden">{{ __('How long we keep it') }}</caption>
            <tbody>
                @foreach(config('privacy.retention') as $record => $rule)
                    <tr><th scope="row" class="fw-semibold">{{ __($record) }}</th><td>{{ __($rule) }}</td></tr>
                @endforeach
            </tbody>
        </table>

        <h2>{{ __('Your choices') }}</h2>
        <p>{{ __('You can update your profile, remove your profile photo, and delete a document while its application is still pending. To ask for your account to be closed, open a support ticket or use the contact form.') }}</p>

        <h2>{{ __('Your consent and requests') }}</h2>
        <p>{{ __('When you create an account you accept these terms and this policy. Each acceptance is recorded with its date and the version accepted. Signed-in users can see that history, download a copy of their data, and ask for their data to be corrected or deleted in the Privacy Center.') }}</p>
        <p>{{ __('This demonstration has not been assessed against any data-protection law, and makes no claim of legal compliance.') }}</p>

        <h2>{{ __('Security') }}</h2>
        <p>{{ __('We apply reasonable safeguards such as access controls, hashed credentials and activity logging. No system is perfectly secure, and this platform has not been independently certified. Please do not upload real identity or legal documents.') }}</p>
    </div></div>
</article>
@endsection
