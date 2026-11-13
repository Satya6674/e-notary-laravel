<form method="POST" action="{{ route('locale.update') }}" class="m-0">
    @csrf
    <select name="locale" class="form-select form-select-sm en-lang" aria-label="{{ __('Language') }}" onchange="this.form.submit()">
        @foreach(\App\Support\Platform::LOCALES as $code => $label)
            <option value="{{ $code }}" {{ app()->getLocale() === $code ? 'selected' : '' }}>{{ $label }}</option>
        @endforeach
    </select>
</form>
