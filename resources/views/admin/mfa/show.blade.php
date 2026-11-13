@extends('layouts.app')

@section('title', __('Security & Two-Factor Authentication'))

@section('content')
<x-ui.page-head :title="__('Security & Two-Factor Authentication')" :subtitle="__('Two-factor sign-in for your own Admin account. Only you can see and change it.')">
    <a href="{{ route('admin.security.index') }}" class="btn btn-outline-primary"><i class="fas fa-arrow-left me-2" aria-hidden="true"></i>{{ __('Back to Security') }}</a>
</x-ui.page-head>

<div class="row">
    <div class="col-lg-8">
        <x-ui.card class="mb-3">
            @if(! $available)
                <p>{{ __('Two-factor sign-in is installed but not switched on yet: its database table is created by migration 2026_11_10_000031, which needs the owner\'s approval to run.') }}</p>
            @elseif($enrolled)
                <p><span class="en-badge en-badge-approved">{{ __('On') }}</span> {{ __('Every sign-in to this account asks for a code from your authenticator app.') }}</p>
                <p class="en-meta">{{ __('Recovery codes left: :n', ['n' => $remaining]) }}</p>

                <form method="POST" action="{{ route('admin.mfa.recovery') }}" class="mb-3">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary">{{ __('Create new recovery codes') }}</button>
                    <span class="form-text d-block">{{ __('The old codes stop working.') }}</span>
                </form>

                @unless($required)
                    <form method="POST" action="{{ route('admin.mfa.disable') }}">
                        @csrf
                        @method('DELETE')
                        <x-ui.field name="code" id="f-disable-code" :label="__('Code from your app, or a recovery code')" required autocomplete="one-time-code" />
                        <button type="submit" class="btn btn-danger">{{ __('Turn off two-factor sign-in') }}</button>
                    </form>
                @else
                    <p class="en-meta">{{ __('Two-factor sign-in is required for Admin accounts, so it cannot be turned off here.') }}</p>
                @endunless
            @elseif($pendingSecret)
                <ol>
                    <li>{{ __('Open an authenticator app (for example Google Authenticator, Microsoft Authenticator or Aegis) and scan this QR code:') }}
                        @if($qr)
                            <div class="my-3"><img src="{{ $qr }}" width="240" height="240" alt="{{ __('QR code for your authenticator app') }}" style="background:#fff;padding:8px;border:1px solid var(--en-gold, #D5AF48);border-radius:8px"></div>
                        @endif
                        <details class="mb-2">
                            <summary>{{ __('Cannot scan? Enter the setup key instead') }}</summary>
                            <div class="my-2"><code class="fs-5" style="letter-spacing:.15em;word-break:break-all">{{ trim(chunk_split($pendingSecret, 4, ' ')) }}</code></div>
                            <span class="en-meta d-block" style="word-break:break-all">{{ __('Or use this setup address:') }} <code>{{ $uri }}</code></span>
                        </details>
                        <span class="en-meta d-block">{{ __('Keep this page private. Do not photograph, share or email the QR code or the key.') }}</span>
                    </li>
                    <li>{{ __('Enter the 6-digit code the app shows.') }}</li>
                </ol>
                <form method="POST" action="{{ route('admin.mfa.confirm') }}">
                    @csrf
                    <x-ui.field name="code" :label="__('6-digit code')" required inputmode="numeric" autocomplete="one-time-code" maxlength="6" />
                    <button type="submit" class="btn btn-primary">{{ __('Turn on') }}</button>
                </form>
            @else
                <p>{{ __('Protect this Admin account with a second step at sign-in: a 6-digit code from an authenticator app on your phone. You also get recovery codes for when the phone is not available.') }}</p>
                @if($required)
                    <p class="en-meta">{{ __('Two-factor sign-in is required for Admin accounts.') }}</p>
                @endif
                <form method="POST" action="{{ route('admin.mfa.enroll') }}">
                    @csrf
                    @unless($stepUpFresh)
                        <x-ui.field name="current_password" type="password" :label="__('Current Password')" required autocomplete="current-password" />
                    @endunless
                    <button type="submit" class="btn btn-primary">{{ __('Set up two-factor sign-in') }}</button>
                </form>
            @endif
        </x-ui.card>
    </div>
</div>
@endsection
