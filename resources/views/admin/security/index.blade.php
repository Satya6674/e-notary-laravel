@extends('layouts.app')

@section('title', __('Security'))

@section('content')
@php($states = ['ok' => ['Working', 'en-badge-approved'], 'ip_blocked' => ['IP not allowed', 'en-badge-rejected'], 'invalid_key' => ['Key rejected', 'en-badge-rejected'], 'no_permission' => ['No permission', 'en-badge-rejected'], 'locked' => ['Locked, no limits set', 'en-badge-pending'],'unknown' => ['Unknown response', 'en-badge-pending'], 'failed' => ['Failed', 'en-badge-rejected'], 'unreachable' => ['Unreachable', 'en-badge-rejected'], 'not_configured' => ['Not configured', 'en-badge-rejected'], 'not_checked' => ['Not checked', 'en-badge-pending']])
<x-ui.page-head :title="__('Security')" :subtitle="__('Live figures from this installation. Nothing here proves compliance.')">
    <a class="btn btn-outline-primary" href="{{ route('admin.security.permissions') }}">{{ __('Permissions') }}</a>
    <a class="btn btn-outline-primary" href="{{ route('admin.security.privacy') }}">{{ __('Privacy Requests') }}</a>
    <a class="btn btn-outline-primary" href="{{ route('admin.security.incidents') }}">{{ __('Incidents') }}</a>
</x-ui.page-head>

@php($mfaOn = \App\Services\AdminMfa::available() && app(\App\Services\AdminMfa::class)->enrolled(auth()->user()))
<x-ui.card class="mb-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
            <strong><i class="fas fa-key me-2" aria-hidden="true"></i>{{ __('Your two-factor sign-in') }}</strong>
            @if($mfaOn)
                <span class="en-badge en-badge-approved ms-2">{{ __('On') }}</span>
            @else
                <span class="en-badge en-badge-pending ms-2">{{ __('Not set up') }}</span>
            @endif
            <span class="en-meta d-block">{{ __('Protects your own Admin account with a code from an authenticator app at every sign-in.') }}</span>
        </div>
        <a class="btn btn-primary" href="{{ route('admin.mfa.show') }}">{{ __('Security & Two-Factor Authentication') }}</a>
    </div>
</x-ui.card>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><x-ui.stat :label="__('Failed Logins, 24 Hours')" :value="$stats['failedLogins']" icon="fa-user-lock" :alert="$stats['failedLogins'] > 20" /></div>
    <div class="col-6 col-lg-3"><x-ui.stat :label="__('Deactivated Accounts')" :value="$stats['deactivated']" icon="fa-user-slash" /></div>
    <div class="col-6 col-lg-3"><x-ui.stat :label="__('Open Privacy Requests')" :value="$stats['openPrivacy']" icon="fa-user-shield" /></div>
    <div class="col-6 col-lg-3"><x-ui.stat :label="__('Open Incidents')" :value="$stats['openIncidents']" icon="fa-triangle-exclamation" :alert="$stats['openIncidents'] > 0" /></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-7">
        <x-ui.card :title="__('Integrations')" flush>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>{{ __('Service') }}</th><th>{{ __('Configured') }}</th><th>{{ __('Last Check') }}</th></tr></thead>
                    <tbody>
                        @foreach($integrations as $item)
                            @php([$label, $badge] = $states[$item['state']] ?? $states['not_checked'])
                            <tr><td>{{ $item['name'] }}</td><td>{{ $item['configured'] ? __('Yes') : __('No') }}</td><td><span class="en-badge {{ $badge }}">{{ __($label) }}</span></td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.security.check') }}">@csrf<button class="btn btn-sm btn-primary" type="submit">{{ __('Check Now') }}</button></form>
                <p class="en-meta mt-2 mb-0">{{ __('The check logs in to each provider. It sends no email or SMS, and it cannot prove that a message reaches a person.') }}</p>
            </div>
        </x-ui.card>
    </div>
    <div class="col-lg-5">
        <x-ui.card :title="__('Queue and SMS')">
            <dl class="row mb-0">
                <dt class="col-7">{{ __('Mail Mode') }}</dt><dd class="col-5">{{ __(['safe_preview' => 'Safe Preview', 'local_gmail' => 'Local Gmail', 'live_test' => 'Live Test', 'live' => 'Live'][$mailMode]) }}</dd>
                <dt class="col-7">{{ __('Queue Worker') }}</dt><dd class="col-5"><span class="en-badge {{ $queue['worker'] ? 'en-badge-approved' : 'en-badge-rejected' }}">{{ __($queue['worker'] ? 'Running' : 'Not running') }}</span></dd>
                <dt class="col-7">{{ __('Scheduler') }}</dt><dd class="col-5"><span class="en-badge {{ $queue['scheduler'] ? 'en-badge-approved' : 'en-badge-rejected' }}">{{ __($queue['scheduler'] ? 'Running' : 'Not running') }}</span></dd>
                <dt class="col-7">{{ __('Waiting in Queue') }}</dt><dd class="col-5">{{ $queue['waiting'] }}</dd>
                @if($queue['held'])<dt class="col-7">{{ __('Held on Other Queues') }}</dt><dd class="col-5">{{ $queue['held'] }}</dd>@endif
                <dt class="col-7">{{ __('Messages Queued Over 15 Minutes') }}</dt><dd class="col-5">{{ $queue['stale'] }}</dd>
                <dt class="col-7">{{ __('Oldest Waiting') }}</dt><dd class="col-5">{{ $queue['oldest'] ?? '-' }}</dd>
                <dt class="col-7">{{ __('Failed') }}</dt><dd class="col-5">{{ $queue['failed'] }}</dd>
                <dt class="col-7">{{ __('SMS Attempts Today') }}</dt><dd class="col-5">{{ $sms['sentToday'] }} / {{ $sms['limit'] }}</dd>
            </dl>
            @if($queue['stuck'])<div class="en-notice mt-2">{{ __('Messages have waited over 15 minutes. Check that a queue worker is running.') }}</div>@endif
        </x-ui.card>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <x-ui.card :title="__('Document Security')">
            <dl class="row mb-2">
                <dt class="col-8">{{ __('Documents on Record') }}</dt><dd class="col-4">{{ $documents['total'] }}</dd>
                <dt class="col-8">{{ __('With a Stored Fingerprint') }}</dt><dd class="col-4">{{ $documents['hashed'] }}</dd>
                <dt class="col-8">{{ __('Removed, Awaiting Purge') }}</dt><dd class="col-4">{{ $documents['removed'] }}</dd>
                <dt class="col-8">{{ __('Opened in the Last 24 Hours') }}</dt><dd class="col-4">{{ $documents['downloads'] }}</dd>
            </dl>
            <p class="en-meta mb-0">{{ __('Files are kept on private storage. They are not scanned for malware and not encrypted by this application.') }}</p>
        </x-ui.card>
    </div>
    <div class="col-lg-6">
        <x-ui.card :title="__('Retention and Backup')">
            <p class="small mb-2">{{ __('Removed documents are kept for 365 days by default, and purging runs only when an administrator starts it. Audit logs are never purged. Unsent drafts are removed after 14 days.') }}</p>
            <p class="en-meta mb-0">{{ __('Automatic backups are not set up on this installation. Backups are taken by hand and a restore must be proven each time.') }}</p>
        </x-ui.card>
    </div>
</div>

<x-ui.card :title="__('Recent Messages')" flush>
    @include('admin.security.deliveries', ['deliveries' => $deliveries])
</x-ui.card>
@endsection
