@extends('layouts.app')

@section('title', __('Two-factor sign-in'))

@section('content')
<x-ui.page-head :title="__('Two-factor sign-in')" />

<div class="row">
    <div class="col-lg-6">
        <x-ui.card>
            <p>{{ __('Enter the 6-digit code from your authenticator app. If your phone is not available, enter one of your recovery codes.') }}</p>
            <form method="POST" action="{{ route('admin.mfa.challenge.verify') }}">
                @csrf
                <x-ui.field name="code" :label="__('Code')" required autocomplete="one-time-code" autofocus maxlength="20" />
                <button type="submit" class="btn btn-primary">{{ __('Continue') }}</button>
            </form>
            <form method="POST" action="{{ route('logout') }}" class="mt-3">
                @csrf
                <button type="submit" class="btn btn-link p-0">{{ __('Sign out') }}</button>
            </form>
        </x-ui.card>
    </div>
</div>
@endsection
