@extends('layouts.app')

@section('title', __('Create Account'))

@section('content')
<div class="en-auth">
    <div class="en-auth-card">
        <x-ui.card>
            <h1>{{ __('Create your account') }}</h1>
            <p class="text-muted">{{ __('Choose how you will use E-Notary.') }}</p>

            <div class="alert alert-danger" id="authError" role="alert" hidden></div>

            {{-- method and action keep the password out of the address bar if the script never runs. --}}
            <form id="registerForm" method="POST" action="{{ route('register.submit') }}" novalidate>
                @csrf
                <fieldset class="en-role-pick">
                    <legend class="visually-hidden">{{ __('Account type') }}</legend>
                    <div><input type="radio" name="role" id="roleCitizen" value="public" checked><label for="roleCitizen"><i class="fas fa-user d-block mb-1" aria-hidden="true"></i>{{ __('Citizen') }}</label></div>
                    <div><input type="radio" name="role" id="roleNotary" value="notary"><label for="roleNotary"><i class="fas fa-user-tie d-block mb-1" aria-hidden="true"></i>{{ __('Notary') }}</label></div>
                </fieldset>

                <x-ui.field name="name" :label="__('Full Name')" required maxlength="255" autocomplete="name" />
                <x-ui.field name="email" type="email" :label="__('Email Address')" required autocomplete="email" />
                <x-ui.field name="mobile" type="tel" :label="__('Mobile Number')" required inputmode="tel" autocomplete="tel" />
                <x-ui.password-field name="password" :label="__('Password')" requirements required autocomplete="new-password" />
                <x-ui.password-field name="password_confirmation" :label="__('Confirm Password')" required autocomplete="new-password" />

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="accept_terms" id="accept_terms" value="1" required>
                    <label class="form-check-label small" for="accept_terms">{{ __('I have read and accept the') }} <a href="{{ route('terms') }}" target="_blank" rel="noopener">{{ __('Terms of Use') }}</a> {{ __('and') }} <a href="{{ route('privacy') }}" target="_blank" rel="noopener">{{ __('Privacy Policy') }}</a>.</label>
                </div>

                <button type="submit" class="btn btn-primary w-100">{{ __('Create Account') }}</button>
            </form>

            <p class="text-center small mt-3 mb-0">{{ __('Already have an account?') }} <a href="{{ route('login') }}">{{ __('Login') }}</a></p>
        </x-ui.card>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.getElementById('registerForm').addEventListener('submit', function (event) {
    event.preventDefault();

    var form = event.target;
    var error = document.getElementById('authError');
    var button = form.querySelector('button[type=submit]');

    error.hidden = true;
    button.disabled = true;

    fetch(@json(route('register.submit')), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({
            name: form.name.value,
            email: form.email.value,
            mobile: form.mobile.value,
            password: form.password.value,
            password_confirmation: form.password_confirmation.value,
            role: form.role.value,
            accept_terms: form.accept_terms.checked ? '1' : ''
        })
    }).then(function (response) {
        return response.json();
    }).then(function (data) {
        if (data.success) { window.location.href = data.redirect; return; }
        error.textContent = '';
        if (Array.isArray(data.errors) && data.errors.length > 1) {
            // Every problem at once, built as text nodes so no message can inject markup.
            var list = document.createElement('ul');
            list.className = 'mb-0 ps-3';
            data.errors.forEach(function (message) {
                var item = document.createElement('li');
                item.textContent = message;
                list.appendChild(item);
            });
            error.appendChild(list);
        } else {
            error.textContent = data.message || @json(__('Registration failed. Please try again.'));
        }
        error.hidden = false;
        button.disabled = false;
    }).catch(function () {
        error.textContent = @json(__('Registration failed. Please try again.'));
        error.hidden = false;
        button.disabled = false;
    });
});
</script>
@endpush
