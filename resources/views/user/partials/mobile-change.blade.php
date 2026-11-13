{{-- Changing the number: password, then an SMS code. The old number stays until the code is entered. --}}
<x-ui.card :title="__('Change mobile number')" class="mt-3">
    <p class="mb-2">
        {{ __('Mobile') }}:
        <strong>{{ $user->mobile ? \App\Services\SmsService::mask($user->mobile) : __('Not added') }}</strong>
    </p>

    @if($pending = $user->pendingMobile())
        <div class="en-notice mb-3" role="status">
            {{ __('Waiting for the code sent to :number. Your current number stays active until you enter it.', ['number' => \App\Services\SmsService::mask($pending)]) }}
        </div>

        <form method="POST" action="{{ route('user.mobile.change.verify') }}" class="mb-3" autocomplete="off">
            @csrf
            <x-ui.field name="otp" :label="__('Enter the 6-digit code')" required inputmode="numeric" maxlength="6" autocomplete="one-time-code" />
            <button type="submit" class="btn btn-primary">{{ __('Verify') }}</button>
        </form>

        <div class="d-flex gap-2 flex-wrap">
            <form method="POST" action="{{ route('user.mobile.change.resend') }}">
                @csrf
                <button type="submit" class="btn btn-outline-primary btn-sm">{{ __('Send a new code') }}</button>
            </form>
            <form method="POST" action="{{ route('user.mobile.change.cancel') }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-outline-secondary btn-sm">{{ __('Cancel') }}</button>
            </form>
        </div>
    @else
        <form method="POST" action="{{ route('user.mobile.change') }}" autocomplete="off">
            @csrf
            <x-ui.field name="mobile" type="tel" :label="__('New mobile number')" :placeholder="__('10-digit Indian mobile number')" required inputmode="numeric" autocomplete="tel-national"
                        :hint="__('We will send a code to the new number. Your current number stays active until you enter it.')" />
            <x-ui.field name="current_password" id="f-mobile-current-password" type="password" :label="__('Current Password')" required autocomplete="current-password" />
            <button type="submit" class="btn btn-outline-primary">{{ __('Send code to new number') }}</button>
        </form>
    @endif
</x-ui.card>
