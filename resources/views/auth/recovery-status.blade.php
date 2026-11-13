@extends('layouts.app')

@section('title', __('Recovery pending'))

@section('content')
<div class="en-auth">
    <div class="en-auth-card">
        <x-ui.card>
            <h1>{{ __('Recovery pending Admin approval') }}</h1>
            <p>{{ __('An administrator must review your request. Nothing has been restored yet, and your new account stays closed while you wait. We will email you when there is a decision.') }}</p>

            @if($recovery)
                <p>{{ __('Reference') }}: <strong>{{ $recovery->reference }}</strong></p>
                @if($recovery->isExpired())
                    <div class="en-notice mb-3" role="status">{{ __('This request is more than 30 days old, so it can no longer be approved. An administrator will close it, and you can then ask again with a fresh code.') }}</div>
                @endif
            @endif

            @if($local)
                <div class="en-notice mb-3" role="status">{{ __('Local development: the administrator sees that your email and mobile checks were simulated, not genuine proof of ownership.') }}</div>
            @endif

            <form method="POST" action="{{ route('account.recovery.cancel') }}" data-confirm="{{ __('Cancel this request and continue with a new account? Your earlier account stays archived.') }}" class="mb-3">
                @csrf
                <button type="submit" class="btn btn-outline-secondary w-100">{{ __('Cancel request and use a new account') }}</button>
            </form>

            <form method="POST" action="{{ route('logout') }}" class="text-center">
                @csrf
                <button type="submit" class="btn btn-link btn-sm p-0">{{ __('Log out') }}</button>
            </form>
        </x-ui.card>
    </div>
</div>
@endsection
