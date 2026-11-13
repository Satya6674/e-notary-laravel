{{-- The tick box and the acting Admin's own password, required on every sensitive action. --}}
<div class="form-check mb-3">
    <input class="form-check-input @error('confirm') is-invalid @enderror" type="checkbox" name="confirm" value="1" id="{{ $prefix }}-confirm" required>
    <label class="form-check-label" for="{{ $prefix }}-confirm">{{ $statement }}</label>
</div>
<div class="mb-3">
    <label class="form-label" for="{{ $prefix }}-password">{{ __('Your own password') }} <span class="en-required" aria-hidden="true">*</span></label>
    <input id="{{ $prefix }}-password" name="admin_password" type="password" class="form-control @error('admin_password') is-invalid @enderror" autocomplete="current-password" required>
</div>
