@extends('layouts.app')

@section('title', __('Login'))

@section('content')
<div class="en-auth">
    <div class="en-auth-card">
        <x-ui.card>
            <h1>{{ __('Welcome back') }}</h1>
            <p class="text-muted">{{ __('Login to your account to continue.') }}</p>

            <div class="alert alert-danger" id="authError" role="alert" hidden></div>

            {{-- method and action keep the password out of the address bar if the script never runs. --}}
            <form id="loginForm" method="POST" action="{{ route('login.submit') }}" novalidate>
                @csrf
                <x-ui.field name="mobile" :label="__('Email or Mobile')" required autocomplete="username" autofocus />
                <x-ui.field name="password" type="password" :label="__('Password')" required autocomplete="current-password" />
                <div class="d-flex justify-content-end mb-3 small"><a href="{{ route('password.request') }}">{{ __('Forgot Password?') }}</a></div>
                <button type="submit" class="btn btn-primary w-100">{{ __('Login') }}</button>
            </form>

            <p class="text-center small mt-3 mb-0">{{ __('Do not have an account?') }} <a href="{{ route('register') }}">{{ __('Create Account') }}</a></p>
        </x-ui.card>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.getElementById('loginForm').addEventListener('submit', function (event) {
    event.preventDefault();

    var form = event.target;
    var error = document.getElementById('authError');
    var button = form.querySelector('button');

    error.hidden = true;
    button.disabled = true;

    fetch(@json(route('login.submit')), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({ mobile: form.mobile.value, password: form.password.value })
    }).then(function (response) {
        return response.json();
    }).then(function (data) {
        if (data.success) { window.location.href = data.redirect; return; }
        error.textContent = data.message || @json(__('Login failed. Please try again.'));
        error.hidden = false;
        button.disabled = false;
    }).catch(function () {
        error.textContent = @json(__('Login failed. Please try again.'));
        error.hidden = false;
        button.disabled = false;
    });
});
</script>
@endpush
