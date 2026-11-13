{{-- Change / add the mobile number before it is verified (POST mobile.verification.number). --}}
<form method="POST" action="{{ route('mobile.verification.number') }}">
    @csrf
    <x-ui.field name="mobile" type="tel" :label="$label ?? __('Mobile number')" :placeholder="__('10-digit Indian mobile number')" required inputmode="numeric" autocomplete="tel-national" />
    <x-ui.field name="current_password" type="password" :label="__('Current Password')" required autocomplete="current-password" />
    <button type="submit" class="btn btn-outline-primary btn-sm">{{ $button ?? __('Save number') }}</button>
</form>
