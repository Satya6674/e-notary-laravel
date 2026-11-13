@extends('layouts.app')

@section('title', __('Welcome Back!'))

@section('content')
<div class="en-auth">
    <div class="en-auth-card">
        <x-ui.card>
            <h1>{{ __('Welcome Back!') }}</h1>
            <p>{{ __('We found a previous E-Notary account associated with your verified email/mobile number. What would you like to do?') }}</p>

            @if($local)
                <div class="en-notice mb-3" role="status">{{ __('Local development: the email and mobile checks here are simulated, not genuine proof of ownership. No SMS was sent, and only your email address was used to find this account.') }}</div>
            @endif

            <div class="border rounded p-3 mb-3">
                <h2 class="h6">{{ __('Recover my old account and data') }}</h2>
                <p class="en-meta">{{ __('We will send you a fresh code to confirm this is you. An administrator must then approve the request. Nothing is restored until they do, and your new account stays closed while you wait.') }}</p>
                <a href="{{ route('account.previous.code') }}" class="btn btn-primary w-100">{{ __('Recover my old account') }}</a>
            </div>

            <div class="border rounded p-3 mb-3">
                <h2 class="h6">{{ __('Create a completely new account') }}</h2>
                <p class="en-meta">{{ __('Continue with a blank profile and a new User ID. Nothing from any earlier account is transferred, and the earlier account stays archived.') }}</p>
                <form method="POST" action="{{ route('account.previous.new') }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary w-100">{{ __('Continue with a new account') }}</button>
                </form>
            </div>

            <form method="POST" action="{{ route('logout') }}" class="text-center">
                @csrf
                <button type="submit" class="btn btn-link btn-sm p-0">{{ __('Log out') }}</button>
            </form>
        </x-ui.card>
    </div>
</div>
@endsection
