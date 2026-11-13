<article class="en-svc-card {{ $service['open'] ? '' : 'is-closed' }}">
    <div class="en-svc-card-head">
        <span class="en-svc-icon" aria-hidden="true"><i class="fas {{ $service['icon'] }}"></i></span>
        @include('partials.service-status', ['service' => $service])
    </div>
    <h3>{{ __($service['name']) }}</h3>
    <p>{{ __($service['summary']) }}</p>
    <a class="en-svc-link" href="{{ route('services.show', $service['slug']) }}">{{ __('Learn More') }}<span class="visually-hidden">: {{ __($service['name']) }}</span> <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
</article>
