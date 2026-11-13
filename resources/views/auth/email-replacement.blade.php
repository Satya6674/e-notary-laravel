@extends('layouts.app')

@section('title', __('Confirm your new email address'))

@section('content')
<div class="en-auth">
    <div class="en-auth-card">
        <x-ui.card>
            <h1>{{ __('Confirm your new email address') }}</h1>
            @if($usable)
                <p>{{ __('Our support team was asked to move an account to this email address. Confirm only if you asked for this.') }}</p>
                <form method="POST" action="{{ route('email-replacement.confirm', ['replacement' => $replacement, 'token' => $token]) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary w-100">{{ __('Confirm this email address') }}</button>
                </form>
            @else
                <div class="alert alert-danger" role="alert">{{ __('This link is no longer valid. It may have been used, cancelled or expired. If you still need help, contact support.') }}</div>
            @endif
        </x-ui.card>
    </div>
</div>
@endsection
