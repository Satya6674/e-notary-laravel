@extends('layouts.app')

@section('title', __('Verify your email'))

@section('content')
<div class="en-auth">
    <div class="en-auth-card">
        <x-ui.card>
            <h1 class="mb-3">{{ __('Verify your email address') }}</h1>

            {{-- Only what really happened to the last code. "Sent" only once the mail service accepted it. --}}
            @if($notSent && ! session('status') && ! session('error'))
                <div class="alert alert-danger" role="alert">{{ __("We couldn't send your verification code. Please try again later.") }}</div>
            @endif

            <p>
                @if($sent)
                    {{ __('A verification code has been sent to your registered email address.') }} <strong>{{ \App\Services\DeliveryLog::maskEmail(auth()->user()->email) }}</strong>
                @endif
                {{ __('Enter the 6-digit code from the email to complete your registration.') }}
            </p>

            <form method="POST" action="{{ route('verification.code') }}" class="mb-3" autocomplete="off">
                @csrf
                <label class="form-label" for="f-email-code">{{ __('Verification code') }}</label>
                <input id="f-email-code" name="code" class="form-control en-otp-input @error('code') is-invalid @enderror" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" aria-describedby="f-email-code-help" @disabled($code['blocked'] > 0)>
                @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                @if($code['blocked'] > 0)
                    <div class="text-danger small mt-1">{{ __('Too many incorrect attempts. Please wait 15 minutes, then request a new code.') }}</div>
                @endif
                <button type="submit" class="btn btn-primary w-100 mt-2" @disabled($code['blocked'] > 0)>{{ __('Verify email address') }}</button>
            </form>
            <p class="en-meta" id="f-email-code-help">
                {{ __('The code works once, for :minutes minutes.', ['minutes' => \App\Services\OtpService::ttl()]) }}
                {{ __('If you cannot find the email, check your Spam or Promotions folder, then request a new code below.') }}
            </p>

            @if(auth()->user()->role === 'notary')
                <div class="en-notice mb-3">
                    {{ __('Notary accounts also need approval by an administrator before you can review requests.') }}
                </div>
            @endif

            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <button type="submit" class="btn btn-outline-secondary w-100">{{ __('Send a new code') }}</button>
            </form>

            <form method="POST" action="{{ route('logout') }}" class="text-center mt-3">
                @csrf
                <button type="submit" class="btn btn-link btn-sm p-0">{{ __('Log out') }}</button>
            </form>
        </x-ui.card>
    </div>
</div>
@endsection
