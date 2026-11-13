{{-- $options: decisions available now. $routePrefix: admin.requests or notary.request. $key: route key. --}}
@php
    $labels = [
        'approve'  => ['Approve', 'btn-success'],
        'correct'  => ['Request Correction', 'btn-warning'],
        'reject'   => ['Reject', 'btn-danger'],
        'complete' => ['Issue Review Certificate', 'btn-primary'],
    ];
@endphp

<div class="card mb-3">
    <div class="card-header"><h2>{{ __($title) }}</h2></div>
    <div class="card-body">
        @if($options)
            @if($warning ?? false)
                <div class="alert alert-warning small py-2">{{ __($warning) }}</div>
            @endif

            <label for="decisionReason" class="form-label">{{ __('Reason') }} <span class="text-danger">*</span></label>
            <textarea id="decisionReason" class="form-control mb-3" rows="3" minlength="3" maxlength="1000" required
                      placeholder="{{ __('Recorded in the audit history and shown to the citizen.') }}">{{ old('reason') }}</textarea>

            <div class="d-grid gap-2">
                @foreach($options as $option)
                    <form method="POST" action="{{ route($routePrefix.'.'.$option, $key) }}" data-decision>
                        @csrf
                        <input type="hidden" name="reason">
                        <button type="submit" class="btn {{ $labels[$option][1] }} w-100">{{ __($labels[$option][0]) }}</button>
                    </form>
                @endforeach
            </div>
        @else
            <p class="text-muted mb-0">{{ __('No decision is available at this status.') }}</p>
        @endif
    </div>
</div>

@push('scripts')
<script>
    document.querySelectorAll('form[data-decision]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var reason = document.getElementById('decisionReason');
            form.elements.reason.value = reason.value.trim();

            if (! reason.checkValidity() || ! form.elements.reason.value) {
                event.preventDefault();
                reason.reportValidity();
            }
        });
    });
</script>
@endpush
