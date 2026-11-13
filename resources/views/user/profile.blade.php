@extends('layouts.app')

@section('title', __('My Profile'))

@section('content')
<x-ui.page-head :title="__('My Profile')" />

@include('partials.profile-photo')

<x-ui.card :title="__('Verification status')" class="mb-3">
    <p class="mb-2">
        {{ __('Email') }}:
        @if($user->hasVerifiedEmail())
            <span class="en-badge en-badge-approved">{{ __('Verified') }}</span>
        @else
            <span class="en-badge en-badge-pending">{{ __('Not verified') }}</span>
        @endif
    </p>

    @if(\App\Models\User::mobileVerificationSupported())
        <p class="mb-2">
            {{ __('Mobile') }}:
            @if($user->hasVerifiedMobile())
                <span class="en-badge en-badge-approved">{{ __('Verified') }}</span>
                <span class="en-meta">{{ __('on :date', ['date' => $user->mobile_verified_at->ist('d M Y')]) }}</span>
            @elseif($user->mobileSimulationAccepted())
                <span class="en-badge en-badge-pending">{{ __('Development Verified') }}</span>
                <span class="en-meta">{{ __('Development email OTP') }}</span>
            @elseif($user->mobileFallbackVerified())
                <span class="en-badge en-badge-assigned">{{ __('Verified via Email Fallback') }}</span> <span class="en-meta">{{ __('No SMS sent') }}</span>
            @else
                <span class="en-badge en-badge-pending">{{ __('Not verified') }}</span>
            @endif
        </p>

        <p class="mb-2">{{ __('Account') }}: <span class="en-badge {{ \App\Support\VerificationStatus::productionVerified($user) ? 'en-badge-approved' : 'en-badge-pending' }}">{{ __(\App\Support\VerificationStatus::for($user)['account']) }}</span></p>

        @if(! $user->hasVerifiedMobile() && ! $user->mobileSimulationAccepted() && ! $user->mobileFallbackVerified())
            @if($user->mobile)
                <div class="row g-2 align-items-end">
                    <div class="col-auto">
                        <form method="POST" action="{{ route('mobile.verification.send') }}">
                            @csrf
                            <button type="submit" class="btn btn-outline-primary btn-sm">{{ \App\Support\VerificationStatus::mode() === \App\Support\VerificationStatus::MODE_EMAIL_FALLBACK ? __('Send code to my email') : __('Send code by SMS to :number', ['number' => '******'.substr($user->mobile, -4)]) }}</button>
                        </form>
                    </div>
                    <div class="col-md-5">
                        <form method="POST" action="{{ route('mobile.verification.verify') }}" class="d-flex gap-2">
                            @csrf
                            <input type="text" name="otp" inputmode="numeric" maxlength="6" autocomplete="one-time-code"
                                   class="form-control form-control-sm @error('otp') is-invalid @enderror" placeholder="{{ __('6-digit code') }}" aria-label="{{ __('6-digit code') }}" required>
                            <button type="submit" class="btn btn-primary btn-sm">{{ __('Verify') }}</button>
                        </form>
                        @error('otp')
                            <div class="text-danger small mt-1">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                <p class="en-meta mt-2 mb-0">{{ __('Codes expire after :minutes minutes; one code per minute, five per hour.', ['minutes' => \App\Services\OtpService::ttl()]) }}</p>
            @else
                <p class="en-meta mb-0">{{ __('Add a 10-digit mobile number below to enable SMS verification.') }}</p>
            @endif
        @endif
    @endif
</x-ui.card>

<div class="row">
    <div class="col-lg-8">
        <x-ui.card>
            <form action="{{ route('user.profile.update') }}" method="POST">
                @csrf
                @method('PUT')

                <x-ui.field name="name" :label="__('Name')" :value="$user->name" required />
                <x-ui.field name="email" type="email" :label="__('Email')" :value="$user->email" :hint="__('Changing your email requires verifying the new address.')" required />

                <hr>

                <x-ui.password-field name="current_password" :label="__('Current Password')" :hint="__('Required to change your email or password.')" autocomplete="current-password" />
                <x-ui.password-field name="password" :label="__('New Password')" :hint="__('Leave blank if you do not want to change your password.')" requirements autocomplete="new-password" />
                <x-ui.password-field name="password_confirmation" :label="__('Confirm New Password')" autocomplete="new-password" />

                <button type="submit" class="btn btn-primary">{{ __('Save Changes') }}</button>
            </form>
        </x-ui.card>

        @include('user.partials.mobile-change')
    </div>
</div>
@endsection
