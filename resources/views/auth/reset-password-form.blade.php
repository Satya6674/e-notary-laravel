@extends('layouts.app')

@section('title', __('Reset Password'))

@section('content')
<div class="en-auth">
    <div class="en-auth-card">
        <x-ui.card>
            <h1 class="mb-3">{{ __('Reset Password') }}</h1>


            <form method="POST" action="{{ route('password.reset.submit') }}">
                @csrf

                <x-ui.password-field name="password" :label="__('New Password')" requirements required autocomplete="new-password" />
                <x-ui.password-field name="password_confirmation" :label="__('Confirm Password')" required autocomplete="new-password" />

                <button type="submit" class="btn btn-primary w-100">{{ __('Reset Password') }}</button>
            </form>

            <p class="text-center small mt-3 mb-0"><a href="{{ route('login') }}">{{ __('Back to Login') }}</a></p>
        </x-ui.card>
    </div>
</div>
@endsection
