@extends('layouts.app')

@section('title', __('Confirm it is you'))

@section('content')
<x-ui.page-head :title="__('Confirm it is you')" />

<div class="row">
    <div class="col-lg-6">
        <x-ui.card>
            <p>{{ __('Sensitive Admin actions need a fresh code from your authenticator app. After you confirm, you can repeat the action for the next :minutes minutes without being asked.', ['minutes' => $minutes]) }}</p>
            @if($fresh)
                <p class="en-meta">{{ __('You have already confirmed. You can go back and repeat the action.') }}</p>
            @endif
            <form method="POST" action="{{ route('admin.reauth.confirm') }}">
                @csrf
                <x-ui.field name="code" :label="__('Code from your app, or a recovery code')" required autocomplete="one-time-code" autocapitalize="off" spellcheck="false" autofocus />
                <button type="submit" class="btn btn-primary">{{ __('Confirm') }}</button>
                <a href="{{ route('admin.dashboard') }}" class="btn btn-outline-primary ms-2">{{ __('Cancel') }}</a>
            </form>
        </x-ui.card>
    </div>
</div>
@endsection
