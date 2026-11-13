<ol class="en-stepper" aria-label="{{ __('Application steps') }}">
    @foreach(['Choose Service', 'Details and Documents', 'Review', 'Submit'] as $index => $label)
        <li class="{{ $index + 1 < $step ? 'is-done' : ($index + 1 === $step ? 'is-now' : '') }}" @if($index + 1 === $step) aria-current="step" @endif><span>{{ $index + 1 }}</span>{{ __($label) }}</li>
    @endforeach
</ol>
