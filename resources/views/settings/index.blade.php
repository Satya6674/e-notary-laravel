@extends('layouts.app')

@section('title', __('Settings'))

@section('content')
<div class="en-page-head"><div><h1>{{ __('Settings') }}</h1></div></div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><h2>{{ __('Language') }}</h2></div>
            <div class="card-body">
                <p class="text-muted small">{{ __('Your choice is saved to your account.') }}</p>
                @include('partials.language-switcher')
            </div>
        </div>
    </div>

    @include('settings.account-security')

    @if($site)
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header"><h2>{{ __('Site Settings') }}</h2></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.settings.update') }}">
                        @csrf
                        @method('PUT')
                        <div class="mb-3">
                            <label class="form-label" for="portal_notice">{{ __('Notice Shown on Portal Pages') }}</label>
                            <textarea id="portal_notice" name="portal_notice" class="form-control" rows="2" maxlength="300">{{ old('portal_notice', $site['portal_notice']) }}</textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="support_email">{{ __('Support Email Shown on the Contact Section') }}</label>
                            <input id="support_email" type="email" name="support_email" class="form-control" value="{{ old('support_email', $site['support_email']) }}">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="default_locale">{{ __('Default Language') }}</label>
                            <select id="default_locale" name="default_locale" class="form-select">
                                @foreach(\App\Support\Platform::LOCALES as $code => $label)
                                    <option value="{{ $code }}" @selected($site['default_locale'] === $code)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-check mb-3">
                            <input id="appointments_enabled" class="form-check-input" type="checkbox" name="appointments_enabled" value="1" @checked($site['appointments_enabled'] === '1')>
                            <label class="form-check-label" for="appointments_enabled">{{ __('Allow citizens to book appointments') }}</label>
                        </div>
                        <button class="btn btn-primary" type="submit">{{ __('Save Settings') }}</button>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection
