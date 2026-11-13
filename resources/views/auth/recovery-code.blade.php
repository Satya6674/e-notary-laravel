@extends('layouts.app')

@section('title', __('Confirm it is you'))

@section('content')
<div class="en-auth">
    <div class="en-auth-card">
        <x-ui.card>
            <h1>{{ __('Confirm it is you') }}</h1>
            <p>{{ __('We will send a 6-digit code to :destination. This is a new code, separate from your registration.', ['destination' => $destination]) }}</p>
            @if($previousAddress)
                <p class="en-meta">{{ __('This is the email address of your previous account. A code to your phone is not enough to recover an account, because phone numbers are sometimes given to someone new. If you can no longer read that mailbox, please contact support.') }}</p>
            @endif

            @if($local)
                <div class="en-notice mb-3" role="status">{{ __('Local development: this code is not filled in for you. Read it from your email and type it here. Even so, it is recorded as a local check, not genuine proof of ownership.') }}</div>
            @endif

            @if($otp['blocked'] > 0)
                <div class="alert alert-danger" role="alert">{{ __('Too many incorrect attempts. Please wait 15 minutes before requesting a new code.') }}</div>
            @endif

            <form method="POST" action="{{ route('account.previous.send') }}" class="mb-3">
                @csrf
                <button type="submit" class="btn btn-outline-primary" @disabled($otp['cooldown'] > 0 || $otp['blocked'] > 0)>{{ __('Send code') }}</button>
                @if($otp['cooldown'] > 0)
                    <span class="en-meta ms-2">{{ __('You can request a new code in') }} {{ $otp['cooldown'] }}{{ __('s') }}</span>
                @endif
            </form>

            <form method="POST" action="{{ route('account.previous.confirm') }}" autocomplete="off">
                @csrf
                <label class="form-label" for="f-recovery-otp">{{ __('Enter the 6-digit code') }}</label>
                <input id="f-recovery-otp" name="otp" class="form-control en-otp-input @error('otp') is-invalid @enderror" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="off">
                @error('otp')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <button type="submit" class="btn btn-primary w-100 mt-2">{{ __('Submit recovery request') }}</button>
            </form>

            <p class="text-center mt-3 mb-0"><a href="{{ route('account.previous') }}">{{ __('Back') }}</a></p>
        </x-ui.card>
    </div>
</div>
@endsection
