@extends('layouts.app')

@section('title', __('Verify a Certificate'))

@section('content')
<div class="en-verify-card">
    <x-ui.page-head :title="__('Verify a Certificate')" :subtitle="__('Enter the 16-character code, or scan the QR code on a certificate.')" />

    <x-ui.card>
        <div class="en-notice mb-3">{{ __('This check confirms that a record exists on E-Notary. It has no legal effect.') }}</div>
        <form method="GET" action="{{ route('verify.index') }}">
            <label for="code" class="form-label">{{ __('Verification Code') }}</label>
            <input id="code" name="code" class="form-control mb-3" maxlength="16" required autocomplete="off" style="text-transform:uppercase;letter-spacing:.08em;">
            <button class="btn btn-primary w-100" type="submit">{{ __('Verify') }}</button>
        </form>
    </x-ui.card>
</div>
@endsection
