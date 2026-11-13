@extends('layouts.app')

@section('title', __('Verify your mobile number'))

@section('content')
<div class="en-auth">
    <div class="en-auth-card">
        <x-ui.card>
            <h1>{{ __('Verify your mobile number') }}</h1>
            @if($fallback)
                <div class="alert alert-warning mb-3" role="status">{{ __('SMS is temporarily unavailable. Your mobile verification code will be sent to your verified email address :email instead. No text message is sent.', ['email' => \App\Services\DeliveryLog::maskEmail($user->email)]) }}</div>
                <p class="text-muted">{{ __('Completing this step records your mobile number as "Verified via Email Fallback". It does not confirm the number by SMS.') }}</p>
            @elseif($bridge)
                <div class="en-notice mb-3" role="status">Local Development Verification — Email OTP used for mobile simulation.</div>
                <p class="text-muted">{{ __('One code is emailed to you and confirms your email address. No SMS is sent, so your mobile number is recorded as a simulation, not as verified by SMS.') }}</p>
            @else
                <p class="text-muted">
                    {{ $user->isNotary()
                        ? __('Confirm the mobile number on file with a one-time code sent by SMS. You can then reset your password by SMS.')
                        : __('Step 2 of 2. Your email address is verified. To finish activating your account, confirm the mobile number on file with a one-time code sent by SMS.') }}
                </p>
            @endif

            @include('auth.partials.verification-status', ['user' => $user])

            @if($user->mobile)
                @unless($bridge || $fallback)
                    <p>{{ __('We can send a 6-digit code to') }} <strong>{{ \App\Services\SmsService::mask($user->mobile) }}</strong>.</p>
                @endunless

                @if($otp['blocked'] > 0)
                    <div class="alert alert-danger" role="alert">{{ __('Too many incorrect attempts. Please wait 15 minutes before requesting a new code.') }}</div>
                @endif

                <form method="POST" action="{{ route('mobile.verification.send') }}" class="mb-4">
                    @csrf
                    <button type="submit" id="otp-send" class="btn btn-outline-primary" data-wait="{{ $otp['cooldown'] }}" @disabled($otp['cooldown'] > 0 || $otp['blocked'] > 0) @if($bridgeAutoSend) data-auto="1" @endif>{{ $bridge ? __('Resend email OTP') : ($fallback ? __($otp['active'] ? 'Send a new code' : 'Send code to my email') : __($otp['active'] ? 'Send a new code' : 'Send code by SMS')) }}</button>
                    <span class="en-meta ms-2" id="otp-wait" @if($otp['cooldown'] <= 0) hidden @endif>{{ __('You can request a new code in') }} <span id="otp-wait-seconds">{{ $otp['cooldown'] }}</span>{{ __('s') }}</span>
                    <div class="form-text">
                        {{ __('Each code is valid for :minutes minutes. You can request a new one after :seconds seconds, at most :max times per hour.', ['minutes' => \App\Services\OtpService::ttl(), 'seconds' => \App\Services\OtpService::RESEND_SECONDS, 'max' => \App\Services\OtpService::MAX_PER_HOUR]) }}
                    </div>
                </form>

                <form method="POST" action="{{ route('mobile.verification.verify') }}" class="mb-4" autocomplete="off">
                    @csrf
                    <label class="form-label" for="f-otp">{{ __('Enter the 6-digit code') }}</label>
                    <input id="f-otp" name="otp" value="{{ $bridgeCode }}" class="form-control en-otp-input @error('otp') is-invalid @enderror" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" aria-describedby="otp-expiry">
                    @error('otp')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text" id="otp-expiry" data-expires="{{ $otp['expires'] }}">
                        @if($otp['active'])
                            <span id="otp-live">{{ __('This code expires in') }} <span id="otp-expiry-time"></span>.</span>
                            <span id="otp-gone" hidden>{{ __('This code has expired. Request a new one.') }}</span>
                        @else
                            {{ __('No code is active. Send a code first.') }}
                        @endif
                    </div>
                    <button type="submit" class="btn btn-primary w-100 mt-2">{{ $bridge ? __('Verify') : __('Verify mobile number') }}</button>
                </form>

                <details class="mb-3" @if($errors->has('mobile')) open @endif @if($user->isNotary()) hidden @endif>
                    <summary class="en-meta">{{ __('Wrong number? Change it here') }}</summary>
                    <div class="mt-2">
                        @include('auth.partials.mobile-number-form', ['label' => __('New mobile number'), 'button' => __('Save new number')])
                    </div>
                </details>
            @else
                <div class="en-notice mb-3" role="status">
                    {{ __('There is no mobile number on your account yet. Add your 10-digit Indian mobile number to receive the code.') }}
                </div>
                @include('auth.partials.mobile-number-form', ['label' => __('Mobile number'), 'button' => __('Save number')])
            @endif

            <form method="POST" action="{{ route('logout') }}" class="text-center mt-3">
                @csrf
                <button type="submit" class="btn btn-link btn-sm p-0">{{ __('Log out') }}</button>
            </form>
        </x-ui.card>
    </div>
</div>
@endsection

@push('scripts')
<script>
    // Display only. The server decides whether a code is valid or a new one may be sent.
    (function () {
        var send = document.getElementById('otp-send');
        var wait = send ? Number(send.dataset.wait) : 0;
        var waitBox = document.getElementById('otp-wait');
        var waitSeconds = document.getElementById('otp-wait-seconds');
        var expiryBox = document.getElementById('otp-expiry');
        var left = expiryBox ? Number(expiryBox.dataset.expires) : 0;
        var time = document.getElementById('otp-expiry-time');

        // Asks for the code through the normal protected form, once.
        if (send && send.dataset.auto) {
            send.form.submit();
            return;
        }

        function tick() {
            if (wait > 0) {
                wait -= 1;
                waitSeconds.textContent = wait;
                if (wait === 0) { send.disabled = false; waitBox.hidden = true; }
            }
            if (time) {
                if (left > 0) {
                    time.textContent = Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0');
                    left -= 1;
                } else {
                    document.getElementById('otp-live').hidden = true;
                    document.getElementById('otp-gone').hidden = false;
                }
            }
        }

        tick();
        setInterval(tick, 1000);
    })();
</script>
@endpush
