<div class="mb-3">
    <label class="form-label" for="{{ $prefix }}-evidence">{{ __('How ownership was confirmed') }} <span class="en-required" aria-hidden="true">*</span></label>
    <select id="{{ $prefix }}-evidence" name="evidence_method" class="form-select @error('evidence_method') is-invalid @enderror" required>
        <option value="">{{ __('Choose…') }}</option>
        @foreach(\App\Services\ManualVerificationService::EVIDENCE_METHODS as $value => $label)
            <option value="{{ $value }}" @selected(old('evidence_method') === $value)>{{ __($label) }}</option>
        @endforeach
    </select>
</div>
