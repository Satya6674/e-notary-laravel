@extends('layouts.app')

@section('title', __('Reset Password'))

@section('content')
<div class="en-auth">
    <div class="en-auth-card">
        <x-ui.card>
            <h1 class="mb-3">{{ __('Choose a new password') }}</h1>

            <form method="POST" action="{{ route('password.update') }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">

                <x-ui.field name="email" type="email" :label="__('Email address')" :value="$email ?? ''" required autocomplete="username" />
                <x-ui.password-field name="password" :label="__('New Password')" requirements required autocomplete="new-password" minlength="8" />
                <x-ui.password-field name="password_confirmation" :label="__('Confirm New Password')" required autocomplete="new-password" minlength="8" />

                <button type="submit" class="btn btn-primary w-100">{{ __('Reset Password') }}</button>
            </form>

            <p class="en-meta mt-3 mb-0">
                {{ __('Reset links expire :minutes minutes after they are requested and can be used once.', ['minutes' => config('auth.passwords.users.expire', 60)]) }}
            </p>

            <p class="text-center small mt-3 mb-0"><a href="{{ route('login') }}">{{ __('Back to Login') }}</a></p>
        </x-ui.card>
    </div>
</div>
@endsection
