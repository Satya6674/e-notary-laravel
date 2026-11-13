@extends('layouts.app')

@section('title', __('Services'))

@section('content')
<x-ui.page-head :title="__('Services')" :subtitle="__('Choose a service to see the documents it needs.')" />

@if($draft && ($draftService = $catalogue->find($draft->service_slug)))
    <div class="en-notice en-draft-note mb-3">
        <span>{{ __('You have an unsent application for :service.', ['service' => __($draftService['name'])]) }}</span>
        <a class="btn btn-sm btn-primary" href="{{ route('user.services.review', $draft->service_slug) }}">{{ __('Continue') }}</a>
    </div>
@endif

<form method="GET" action="{{ route('user.services.index') }}" class="en-svc-search" role="search">
    <label class="visually-hidden" for="service-search">{{ __('Search services') }}</label>
    <input id="service-search" class="form-control" type="search" name="q" value="{{ $search }}" placeholder="{{ __('Search services') }}" autocomplete="off">
    @if($category)<input type="hidden" name="category" value="{{ $category }}">@endif
    <button class="btn btn-outline-primary" type="submit">{{ __('Search') }}</button>
</form>

<nav class="en-pills" aria-label="{{ __('Service categories') }}">
    <a href="{{ route('user.services.index') }}" class="{{ $category ? '' : 'is-active' }}">{{ __('All') }}</a>
    @foreach($catalogue->categories() as $key => $item)
        <a href="{{ route('user.services.index', ['category' => $key]) }}" class="{{ $category === $key ? 'is-active' : '' }}">{{ __($item['name']) }}</a>
    @endforeach
</nav>

<div class="en-picker">
    <div class="en-picker-list">
        <p class="en-meta" id="no-services" @if($services->isNotEmpty()) hidden @endif>{{ __('No services match your search.') }}</p>
        @foreach($services as $service)
            <a class="en-pick {{ ($selected['slug'] ?? null) === $service['slug'] ? 'is-selected' : '' }}"
               href="{{ route('user.services.index', array_filter(['service' => $service['slug'], 'q' => $search, 'category' => $category])) }}"
               data-service="{{ $service['slug'] }}" data-search="{{ strtolower(__($service['name']).' '.$service['name'].' '.__($service['summary'])) }}"
               @if(($selected['slug'] ?? null) === $service['slug']) aria-current="true" @endif>
                <span class="en-svc-icon" aria-hidden="true"><i class="fas {{ $service['icon'] }}"></i></span>
                <span class="en-pick-text">
                    <strong>{{ __($service['name']) }}</strong>
                    <span>{{ __($service['summary']) }}</span>
                    <span class="en-pick-cat">{{ __($catalogue->categories()[$service['category']]['name']) }}</span>
                </span>
                @include('partials.service-status', ['service' => $service])
            </a>
        @endforeach
    </div>

    <aside class="en-picker-panel" id="service-panel" aria-live="polite" aria-label="{{ __('Document requirements') }}">
        <p class="en-pick-empty" @if($selected) hidden @endif><i class="fas fa-hand-pointer" aria-hidden="true"></i>{{ __('Select a service to see its document requirements.') }}</p>
        @foreach($services as $service)
            <div data-panel="{{ $service['slug'] }}" @if(($selected['slug'] ?? null) !== $service['slug']) hidden @endif>
                @component('partials.service-requirements', ['service' => $service, 'draft' => $draft?->service_slug === $service['slug'] ? $draft : null])
                    <div class="en-req-cta">
                        @if($catalogue->canApply($service))
                            <a class="btn btn-primary w-100" href="{{ route('user.services.apply', $service['slug']) }}">{{ __('Continue to Application') }}</a>
                        @elseif($service['open'])
                            <a class="btn btn-primary w-100" href="{{ route($service['destination']) }}">{{ __('Open') }}</a>
                        @endif
                    </div>
                @endcomponent
            </div>
        @endforeach
    </aside>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        var picks = Array.prototype.slice.call(document.querySelectorAll('[data-service]'));
        var panels = document.querySelectorAll('[data-panel]');
        var empty = document.querySelector('.en-pick-empty');
        var none = document.getElementById('no-services');

        picks.forEach(function (pick) {
            pick.addEventListener('click', function (event) {
                event.preventDefault();
                picks.forEach(function (item) {
                    item.classList.toggle('is-selected', item === pick);
                    if (item === pick) { item.setAttribute('aria-current', 'true'); } else { item.removeAttribute('aria-current'); }
                });
                panels.forEach(function (panel) { panel.hidden = panel.dataset.panel !== pick.dataset.service; });
                empty.hidden = true;
                history.replaceState(null, '', pick.href);
                if (window.matchMedia('(max-width: 991px)').matches) {
                    document.getElementById('service-panel').scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });

        document.getElementById('service-search').addEventListener('input', function () {
            var term = this.value.trim().toLowerCase();
            var shown = 0;
            picks.forEach(function (pick) {
                pick.hidden = term !== '' && pick.dataset.search.indexOf(term) === -1;
                shown += pick.hidden ? 0 : 1;
            });
            none.hidden = shown > 0;
        });
    })();
</script>
@endpush
