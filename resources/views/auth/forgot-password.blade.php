@extends('layouts.app')

@section('title', __('Forgot Password'))

@section('content')
<div class="en-auth">
    <div class="en-auth-card">
        <x-ui.card>
            <h1>{{ __('Forgot Password') }}</h1>

            {{-- Step 2: the code. The page never says whether an account matched. --}}
            @if($awaitingCode)
                <p class="text-muted">{{ __('Enter the 6-digit verification code sent to the registered email address of your account. The code expires in :minutes minutes.', ['minutes' => \App\Services\OtpService::ttl()]) }}</p>

                <form method="POST" action="{{ route('password.verify.otp') }}" class="mb-4" autocomplete="off">
                    @csrf
                    <x-ui.field name="otp" :label="__('Verification code')" :placeholder="__('6-digit code')" required inputmode="numeric" maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code" />
                    <button type="submit" class="btn btn-primary w-100">{{ __('Verify code') }}</button>
                </form>

                <p class="text-muted small mb-2">{{ __('Did not receive it? Check your Spam folder, or request a new code.') }}</p>
                <form method="POST" action="{{ route('password.email') }}" class="d-flex gap-2 flex-wrap align-items-center mb-3">
                    @csrf
                    <input type="hidden" name="identifier" value="{{ \Illuminate\Support\Str::after((string) session('reset.identifier'), ':') }}">
                    <button type="submit" class="btn btn-outline-secondary btn-sm">{{ __('Send a new code') }}</button>
                    <span class="en-meta">{{ __('One code per minute.') }}</span>
                </form>
                <form method="POST" action="{{ route('password.otp.restart') }}">
                    @csrf
                    <button type="submit" class="btn btn-link btn-sm p-0">{{ __('Use a different email or mobile number') }}</button>
                </form>
            @else
                {{-- Step 1. --}}
                <p class="text-muted">{{ __('Enter the email address or mobile number registered to your account. We will send a 6-digit verification code to the account\'s registered email address.') }}</p>

                <form method="POST" action="{{ route('password.email') }}">
                    @csrf

                    <x-ui.field name="identifier" :label="__('Email or Mobile')" required autocomplete="username" />

                    <button type="submit" class="btn btn-primary w-100">{{ __('Send verification code') }}</button>
                </form>
            @endif

            <p class="text-center small mt-3 mb-0"><a href="{{ route('login') }}">{{ __('Back to Login') }}</a></p>
        </x-ui.card>
    </div>
</div>
@endsection
