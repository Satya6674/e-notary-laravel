@extends('layouts.app')

@section('title', __('My Profile'))

@section('content')
<x-ui.page-head :title="__('My Profile')" />

@include('partials.profile-photo')

<x-ui.card class="mb-3">
    <dl class="row mb-0">
        <dt class="col-sm-3">{{ __('Full Name') }}</dt>
        <dd class="col-sm-9">{{ auth()->user()->name ?? __('N/A') }}</dd>

        <dt class="col-sm-3">{{ __('Email') }}</dt>
        <dd class="col-sm-9">{{ auth()->user()->email ?? __('N/A') }}</dd>

        <dt class="col-sm-3">{{ __('Role') }}</dt>
        <dd class="col-sm-9">{{ __(ucfirst(auth()->user()->role ?? 'notary')) }}</dd>

        <dt class="col-sm-3">{{ __('Phone') }}</dt>
        <dd class="col-sm-9">
            {{ auth()->user()->mobile ?? __('N/A') }}
            @if(auth()->user()->hasVerifiedMobile())
                <span class="en-badge en-badge-approved">{{ __('Verified') }}</span>
            @elseif(auth()->user()->mobileSimulationAccepted())
                <span class="en-badge en-badge-pending">{{ __('Development Verified') }}</span>
            @elseif(auth()->user()->mobileFallbackVerified())
                <span class="en-badge en-badge-assigned">{{ __('Verified via Email Fallback') }}</span> <span class="en-meta">{{ __('No SMS sent') }}</span>
            @elseif(auth()->user()->mobile && auth()->user()->isApprovedNotary())
                <a href="{{ route('verification.mobile.notice') }}" class="ms-2">{{ __('Verify your mobile number') }}</a>
            @endif
        </dd>

        <dt class="col-sm-3">{{ __('Created Date') }}</dt>
        <dd class="col-sm-9 mb-0">{{ auth()->user()->created_at?->ist('d M Y') ?? __('N/A') }}</dd>
    </dl>
</x-ui.card>

<div class="row">
    <div class="col-lg-8">
        <x-ui.card :title="__('Update Password')">
            <form method="POST" action="{{ route('notary.profile.password') }}">
                @csrf

                <x-ui.password-field name="current_password" :label="__('Current Password')" required autocomplete="current-password" />
                <x-ui.password-field name="password" :label="__('New Password')" requirements required autocomplete="new-password" />
                <x-ui.password-field name="password_confirmation" :label="__('Confirm Password')" required autocomplete="new-password" />

                <button type="submit" class="btn btn-primary">{{ __('Update Password') }}</button>
            </form>
        </x-ui.card>
    </div>
</div>
@endsection
