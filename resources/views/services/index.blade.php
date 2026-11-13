@extends('layouts.app')

@section('title', __('Services'))

@section('fullwidth', true)

@section('content')
<section class="en-page-hero">
    <div class="en-wrap">
        <span class="en-eyebrow">{{ __('Our Services') }}</span>
        <h1>{{ $category ? __($catalogue->categories()[$category]['name']) : __('All Services') }}</h1>
        <p>{{ __('See what is open today and what is planned. Each service shows its current status.') }}</p>
    </div>
</section>

<section class="en-section">
    <div class="en-wrap">
        <nav class="en-pills" aria-label="{{ __('Service categories') }}">
            <a href="{{ route('services.index') }}" class="{{ $category ? '' : 'is-active' }}" @unless($category) aria-current="page" @endunless>{{ __('All') }}</a>
            @foreach($catalogue->categories() as $key => $item)
                <a href="{{ route('services.index', ['category' => $key]) }}" class="{{ $category === $key ? 'is-active' : '' }}" @if($category === $key) aria-current="page" @endif>{{ __($item['name']) }}</a>
            @endforeach
        </nav>

        <div class="en-status-key">
            <p><span class="en-svc-status is-available">{{ __('Available') }}</span> {{ __('Works on the platform today.') }}</p>
            <p><span class="en-svc-status is-limited">{{ __('Limited') }}</span> {{ __('You can apply and be reviewed. Requirements are unconfirmed, and the outcome has no legal effect.') }}</p>
            <p><span class="en-svc-status is-coming_soon">{{ __('Coming Soon') }}</span> {{ __('Planned, and not open for applications.') }}</p>
        </div>

        @foreach($catalogue->categories() as $key => $item)
            @php($rows = $services->where('category', $key))
            @if($rows->isNotEmpty())
                <h2 class="en-group-title">{{ __($item['name']) }}</h2>
                <div class="en-svc-grid">
                    @foreach($rows as $service)
                        @include('partials.service-card')
                    @endforeach
                </div>
            @endif
        @endforeach
    </div>
</section>
@endsection
