{{--
    A password input with a show/hide toggle and, for a NEW password, the policy checklist
    (App\Support\PasswordPolicy: 8+ characters, uppercase, lowercase, number, special character).
    The checklist only guides; the server always enforces the policy. Never pre-filled.

    <x-ui.password-field name="password" :label="__('New Password')" requirements required autocomplete="new-password" />
--}}
@props(['name', 'label', 'required' => false, 'hint' => null, 'id' => null, 'requirements' => false])
@php($fid = $id ?? 'f-'.$name)
@php($eid = $id ? 'e-'.$id : 'e-'.$name)
@php($rid = $fid.'-rules')
@php($describedBy = trim(($errors->has($name) ? $eid.' ' : '').($requirements ? $rid : '')))
<div class="mb-3">
    <label class="form-label" for="{{ $fid }}">{{ $label }}@if($required) <span class="en-required" aria-hidden="true">*</span>@endif</label>
    <div class="input-group has-validation en-pw-group">
        <input id="{{ $fid }}" name="{{ $name }}" type="password" @required($required)
            @if($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            @error($name) aria-invalid="true" @enderror
            {{ $attributes->class(['form-control', 'is-invalid' => $errors->has($name)]) }}>
        <button type="button" class="btn en-pw-toggle" data-password-toggle aria-controls="{{ $fid }}" aria-pressed="false"
            aria-label="{{ __('Show password') }}" data-label-show="{{ __('Show password') }}" data-label-hide="{{ __('Hide password') }}">
            <i class="fas fa-eye" aria-hidden="true"></i>
        </button>
        @error($name)<div class="invalid-feedback" id="{{ $eid }}">{{ $message }}</div>@enderror
    </div>
    @if($hint)<div class="form-text">{{ $hint }}</div>@endif

    @if($requirements)
        <div class="en-pw-rules" id="{{ $rid }}" data-password-rules="{{ $fid }}"
            data-label-met="{{ __('Requirement met') }}" data-label-unmet="{{ __('Requirement not met') }}">
            <div class="en-pw-rules-title">{{ __('Password requirements') }}</div>
            <ul class="list-unstyled mb-0">
                <li data-rule="length"><i class="fas fa-circle-check" aria-hidden="true"></i> {{ __('At least 8 characters') }} <span class="visually-hidden en-pw-state">{{ __('Requirement not met') }}</span></li>
                <li data-rule="upper"><i class="fas fa-circle-check" aria-hidden="true"></i> {{ __('One uppercase letter (A–Z)') }} <span class="visually-hidden en-pw-state">{{ __('Requirement not met') }}</span></li>
                <li data-rule="lower"><i class="fas fa-circle-check" aria-hidden="true"></i> {{ __('One lowercase letter (a–z)') }} <span class="visually-hidden en-pw-state">{{ __('Requirement not met') }}</span></li>
                <li data-rule="number"><i class="fas fa-circle-check" aria-hidden="true"></i> {{ __('One number (0–9)') }} <span class="visually-hidden en-pw-state">{{ __('Requirement not met') }}</span></li>
                <li data-rule="symbol"><i class="fas fa-circle-check" aria-hidden="true"></i> {{ __('One special character (for example @ # $ ! % &)') }} <span class="visually-hidden en-pw-state">{{ __('Requirement not met') }}</span></li>
            </ul>
            <div class="en-pw-numbers-only" data-numbers-only hidden role="status">{{ __('A password cannot be numbers only. Use uppercase and lowercase letters, a number and a special character.') }}</div>
        </div>
    @endif
</div>

@once
    @push('scripts')
    <script>
    (function () {
        // Show / hide: the button switches the input's type; nothing is copied or stored.
        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-password-toggle]');
            if (!button) { return; }
            var input = document.getElementById(button.getAttribute('aria-controls'));
            if (!input) { return; }
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.setAttribute('aria-pressed', show ? 'true' : 'false');
            button.setAttribute('aria-label', show ? button.dataset.labelHide : button.dataset.labelShow);
            var icon = button.querySelector('i');
            if (icon) { icon.className = show ? 'fas fa-eye-slash' : 'fas fa-eye'; }
        });

        // Checklist: the same tests as Laravel's Password rule (min, mixedCase, numbers, symbols).
        var tests = {
            length: function (v) { return v.length >= 8; },
            upper: function (v) { return /\p{Lu}/u.test(v); },
            lower: function (v) { return /\p{Ll}/u.test(v); },
            number: function (v) { return /\p{N}/u.test(v); },
            symbol: function (v) { return /[\p{Z}\p{S}\p{P}]/u.test(v); }
        };
        document.querySelectorAll('[data-password-rules]').forEach(function (rules) {
            var input = document.getElementById(rules.dataset.passwordRules);
            if (!input) { return; }
            var update = function () {
                var value = input.value;
                rules.querySelectorAll('[data-rule]').forEach(function (item) {
                    var met = tests[item.dataset.rule](value);
                    item.classList.toggle('is-met', met);
                    item.querySelector('.en-pw-state').textContent = met ? rules.dataset.labelMet : rules.dataset.labelUnmet;
                });
                rules.querySelector('[data-numbers-only]').hidden = !(value !== '' && /^[0-9]+$/.test(value));
            };
            input.addEventListener('input', update);
            update();
        });
    })();
    </script>
    @endpush
@endonce
