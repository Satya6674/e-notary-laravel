@extends('layouts.app')

@section('title', __('View User'))

@section('content')
@php
    $roleLabels = ['public' => __('Citizen'), 'notary' => __('Notary'), 'admin' => __('Admin')];
@endphp

<x-ui.page-head :title="__('User Details')">
    <a href="{{ route('admin.users.index') }}" class="btn btn-outline-primary"><i class="fas fa-arrow-left me-2" aria-hidden="true"></i>{{ __('Back to Users') }}</a>
    {{-- Only for a restored account still waiting for its new password (EmailReplacementService). --}}
    @if(\App\Models\EmailReplacement::supported() && ! $user->isDeleted() && ! $user->isAdmin() && $user->mustResetPassword())
        <a href="{{ route('admin.users.email-replacement', $user) }}" class="btn btn-outline-warning">{{ __('Replace lost email address') }}</a>
    @endif
    @if(in_array($user->role, ['public', 'notary'], true))
        <a href="{{ route('admin.verification.show', $user) }}" class="btn btn-outline-primary">{{ __('Manage verification') }}</a>
    @endif
</x-ui.page-head>

<x-ui.card>
    <dl class="row mb-0">
        <dt class="col-sm-3">{{ __('ID') }}</dt>
        <dd class="col-sm-9">{{ $user->id }}</dd>

        <dt class="col-sm-3">{{ __('Name') }}</dt>
        <dd class="col-sm-9">{{ $user->name }}</dd>

        <dt class="col-sm-3">{{ __('Email') }}</dt>
        <dd class="col-sm-9">{{ $user->contactEmail() }}</dd>

        <dt class="col-sm-3">{{ __('Mobile') }}</dt>
        <dd class="col-sm-9">{{ $user->contactMobile() }}</dd>

        <dt class="col-sm-3">{{ __('Role') }}</dt>
        <dd class="col-sm-9">{{ $roleLabels[$user->role] ?? $user->role }}</dd>

        <dt class="col-sm-3">{{ __('Status') }}</dt>
        <dd class="col-sm-9">
            @if($user->isDeleted())
                <span class="en-badge en-badge-rejected">{{ __('Deleted') }}</span>
                <span class="en-meta">{{ __('on :date', ['date' => $user->deleted_at->ist('d M Y')]) }}</span>
            @elseif($user->deactivated_at)
                <span class="en-badge en-badge-rejected">{{ __('Deactivated') }}</span>
                <span class="en-meta">{{ __('on :date', ['date' => $user->deactivated_at->ist('d M Y')]) }}</span>
            @else
                <span class="en-badge en-badge-approved">{{ __('Active') }}</span>
            @endif
        </dd>

        <dt class="col-sm-3">{{ __('Email verified') }}</dt>
        <dd class="col-sm-9">
            {{-- The method, from the one place that names it: OTP, signed link, manual check. --}}
            @if($user->email_verified_at)
                @php([$emailLabel, $emailClass] = \App\Support\VerificationStatus::badges($user)['email'])
                <span class="en-badge {{ $emailClass }}">{{ __($emailLabel) }}</span>
                <span class="en-meta">{{ __('on :date', ['date' => $user->email_verified_at->ist('d M Y')]) }}</span>
            @else
                <span class="en-badge en-badge-pending">{{ __('Not verified') }}</span>
            @endif
        </dd>

        @if(\App\Models\User::mobileVerificationSupported())
            <dt class="col-sm-3">{{ __('Mobile verified') }}</dt>
            <dd class="col-sm-9">
                @if($user->hasVerifiedMobile())
                    <span class="en-badge en-badge-approved">{{ __('Verified') }}</span>
                    <span class="en-meta">{{ __('on :date', ['date' => $user->mobile_verified_at->ist('d M Y')]) }}</span>
                @elseif($user->mobileSimulationAccepted())
                    <span class="en-badge en-badge-pending">{{ __('Development Verified') }}</span>
                @elseif($user->mobileFallbackVerified())
                    <span class="en-badge en-badge-assigned">{{ __('Verified via Email Fallback') }}</span> <span class="en-meta">{{ __('No SMS sent') }}</span>
                    <span class="en-meta">{{ __('on :date', ['date' => $user->mobile_fallback_verified_at->ist('d M Y')]) }}</span>
                @elseif($user->mobileManualVerified())
                    <span class="en-badge en-badge-assigned">{{ __('Verified Manually by Admin') }}</span> <span class="en-meta">{{ __('No SMS sent') }}</span>
                    <span class="en-meta">{{ __('on :date', ['date' => $user->mobile_manual_verified_at->ist('d M Y')]) }}</span>
                @else
                    <span class="en-badge en-badge-pending">{{ __('Not verified') }}</span>
                @endif
            </dd>
        @endif

        @if($user->isNotary())
            <dt class="col-sm-3">{{ __('Admin approval') }}</dt>
            <dd class="col-sm-9">
                @if($user->notary_approved_at)
                    <span class="en-badge en-badge-approved">{{ __('Approved') }}</span>
                    <span class="en-meta">{{ __('on :date', ['date' => $user->notary_approved_at->ist('d M Y')]) }}</span>
                @elseif($user->isRejectedNotary())
                    @php($decision = $user->notaryDecision())
                    <span class="en-badge en-badge-rejected">{{ __('Rejected') }}</span>
                    <span class="en-meta">{{ __('on :date', ['date' => $decision->created_at->ist('d M Y')]) }}@if($decision->meta['reason'] ?? null) · {{ $decision->meta['reason'] }}@endif</span>
                @else
                    <span class="en-badge en-badge-pending">{{ __('Pending') }}</span>
                @endif
            </dd>
        @endif

        @unless($user->isAdmin())
            @php($consents = app(\App\Services\ConsentService::class))
            <dt class="col-sm-3">{{ __('Terms and privacy consent') }}</dt>
            <dd class="col-sm-9">
                @if($latest = $consents->history($user)->first())
                    <span class="en-badge {{ $consents->isCurrent($user) ? 'en-badge-approved' : 'en-badge-pending' }}">{{ $consents->isCurrent($user) ? __('Current') : __('Older version') }}</span>
                    <span class="en-meta">{{ __('on :date', ['date' => $latest->created_at->ist('d M Y H:i')]) }} · {{ __('Terms of Use') }} {{ $latest->meta['terms_version'] ?? '-' }}, {{ __('Privacy Policy') }} {{ $latest->meta['privacy_version'] ?? '-' }}</span>
                @else
                    <span class="en-badge en-badge-pending">{{ __('Not recorded') }}</span>
                @endif
            </dd>
        @endunless

        <dt class="col-sm-3">{{ __('Created At') }}</dt>
        <dd class="col-sm-9 mb-0">{{ $user->created_at?->ist('d M Y H:i') }}</dd>
    </dl>
</x-ui.card>

{{-- Read-only. Each verification is its own entry, so an email fallback and a later SMS both stay on record. --}}
<div class="card mt-3">
    <div class="card-header"><h2>{{ __('Verification history') }}</h2></div>
    @if(! $verificationHistorySupported)
        <div class="card-body"><p class="en-meta mb-0">{{ __('Verification history appears here once the database update for it has been applied.') }}</p></div>
    @elseif($verificationHistory->isEmpty())
        <div class="card-body"><p class="en-meta mb-0">{{ __('No verification has been recorded for this account yet.') }}</p></div>
    @else
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Purpose') }}</th><th>{{ __('Method') }}</th><th>{{ __('Address or number') }}</th><th>{{ __('Delivered via') }}</th><th>{{ __('SMS') }}</th></tr></thead>
                <tbody>
                    @foreach($verificationHistory as $event)
                        <tr>
                            <td class="text-nowrap">{{ \Illuminate\Support\Carbon::parse($event->verified_at ?? $event->created_at)->ist('d M Y H:i') }}</td>
                            <td>{{ $event->purpose === 'email' ? __('Email') : __('Mobile') }}</td>
                            <td>
                                @switch($event->method)
                                    @case('email_otp') {{ __('Email OTP') }} @break
                                    @case('signed_link') {{ __('Email link') }} @break
                                    @case(\App\Models\User::METHOD_EMAIL_FALLBACK) <span class="en-badge en-badge-assigned">{{ __('Verified via Email Fallback') }}</span> @break
                                    @case(\App\Models\User::METHOD_SMS) {{ __('SMS OTP') }} @break
                                    @case(\App\Models\User::METHOD_MOCK_SMS) {{ __('Mock SMS, no phone reached') }} @break
                                    @case(\App\Models\User::METHOD_DEV_EMAIL) {{ __('Development email OTP') }} @break
                                    @case(\App\Models\User::METHOD_ADMIN_MANUAL) <span class="en-badge en-badge-assigned">{{ __('Verified Manually by Admin') }}</span> @break
                                    @default {{ $event->method }}
                                @endswitch
                            </td>
                            <td>{{ $event->target_masked }}</td>
                            <td>{{ match ($event->delivered_via) { 'sms' => __('SMS'), 'admin' => __('Nothing sent (Admin check)'), default => __('Email') } }}</td>
                            <td>{{ match ($event->sms_status) { \App\Models\User::SMS_NOT_SENT => __('Not sent'), \App\Models\User::SMS_DELIVERED => __('Delivered'), default => '-' } }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
