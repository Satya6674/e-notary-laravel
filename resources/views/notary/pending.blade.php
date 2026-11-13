@extends('layouts.app')

@section('title', __('Awaiting Approval'))

@section('content')
<x-ui.page-head :title="__('Awaiting Approval')" />

@if($rejected)
<x-ui.card class="mb-3">
    <div class="alert alert-danger mb-3" role="status">{{ __('An administrator did not approve your notary account.') }}@if($decided) <span class="text-nowrap">({{ $decided->ist('d M Y') }})</span>@endif</div>
    @if($reason)<p class="mb-3"><strong>{{ __('Reason') }}:</strong> {{ $reason }}</p>@endif
    <p class="mb-0">{{ __('If you think this is wrong, or you can supply what was missing, write to us with the contact form. An administrator can review your account again.') }} <a href="{{ route('home') }}#contact">{{ __('Contact Us') }}</a></p>
</x-ui.card>
@else
<x-ui.card class="mb-3">
    <p class="mb-3">{{ __('Your notary account is waiting for administrator approval. Your portal will open here once it is approved.') }}</p>
    <p class="mb-0">{{ __('You will be told here and by email when an administrator has reviewed your account.') }}</p>
</x-ui.card>
@endif

<x-ui.card class="mb-3">
    <dl class="row mb-0">
        <dt class="col-sm-3">{{ __('Email') }}</dt>
        <dd class="col-sm-9">
            @if($user->hasVerifiedEmail())
                <span class="en-badge en-badge-approved">{{ __('Verified') }}</span>
            @else
                <span class="en-badge en-badge-pending">{{ __('Not verified') }}</span>
            @endif
        </dd>

        <dt class="col-sm-3">{{ __('Mobile Number') }}</dt>
        <dd class="col-sm-9">
            @if($user->hasVerifiedMobile())
                <span class="en-badge en-badge-approved">{{ __('Verified') }}</span>
            @elseif($user->mobileSimulationAccepted())
                <span class="en-badge en-badge-pending">{{ __('Development Verified') }}</span>
            @elseif($user->mobileFallbackVerified())
                <span class="en-badge en-badge-assigned">{{ __('Verified via Email Fallback') }}</span> <span class="en-meta">{{ __('No SMS sent') }}</span>
            {{-- Only while the step is open to a Notary awaiting approval: the email fallback or the local bridge. Never paid SMS. --}}
            @elseif($user->mobile && \App\Models\User::mobileVerificationSupported()
                && (\App\Support\VerificationStatus::mode() === \App\Support\VerificationStatus::MODE_EMAIL_FALLBACK || \App\Services\MobileOtpEmailBridge::enabled()))
                <span class="en-badge en-badge-pending">{{ __('Optional') }}</span>
                <a href="{{ route('verification.mobile.notice') }}" class="ms-2">{{ __('Verify your mobile number') }}</a>
            @else
                <span class="en-badge en-badge-pending">{{ __('Optional') }}</span>
            @endif
        </dd>
    </dl>
</x-ui.card>

<p class="en-meta">{{ __('E-Notary is an independent software platform. It is not a government service, is not affiliated with any government body, and provides no legally recognised notarisation.') }}</p>
@endsection
