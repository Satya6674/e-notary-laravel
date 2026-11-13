@php($serviceGroups = app(\App\Services\ServiceCatalogue::class)->openGroups())
<div class="en-site-top en-no-print">
    <div class="en-wrap">
        <span class="en-tagline">{{ __('Independent Software Platform') }}</span>
        <span class="en-top-tools">
            <a href="{{ route('verify.index') }}"><i class="fas fa-qrcode me-1" aria-hidden="true"></i>{{ __('Verify a Certificate') }}</a>
            @include('partials.language-switcher')
        </span>
    </div>
</div>
<nav class="en-site-nav en-no-print" aria-label="{{ __('Main navigation') }}">
    <div class="en-wrap">
        <a class="en-logo" href="{{ route('home') }}">
            <span class="en-brand-mark" aria-hidden="true"><i class="fas fa-gavel"></i></span>
            <span class="en-brand-name">E-Notary</span>
        </a>
        <button type="button" class="en-site-toggle" aria-label="{{ __('Menu') }}" aria-expanded="false" aria-controls="siteMenu"
                onclick="this.setAttribute('aria-expanded', document.getElementById('siteMenu').classList.toggle('is-open'))"><i class="fas fa-bars" aria-hidden="true"></i></button>
        <div class="en-site-menu" id="siteMenu">
            <ul class="en-site-links">
                <li><a href="{{ route('home') }}" @if(request()->routeIs('home')) aria-current="page" @endif>{{ __('Home') }}</a></li>
                <li class="dropdown">
                    <button type="button" class="en-menu-button {{ request()->routeIs('services.*') ? 'is-active' : '' }}" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
                        {{ __('Services') }} <i class="fas fa-chevron-down" aria-hidden="true"></i>
                    </button>
                    <div class="dropdown-menu en-mega">
                        <div class="en-mega-grid">
                            @foreach($serviceGroups as $group)
                                <div>
                                    <a class="dropdown-item en-mega-title" href="{{ route('services.index', ['category' => $group['key']]) }}"><i class="fas {{ $group['icon'] }}" aria-hidden="true"></i>{{ __($group['name']) }}</a>
                                    @foreach($group['services']->take(5) as $service)
                                        <a class="dropdown-item" href="{{ route('services.show', $service['slug']) }}">{{ __($service['name']) }}</a>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                        <a class="dropdown-item en-mega-all" href="{{ route('services.index') }}">{{ __('View All Services') }} <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                    </div>
                </li>
                <li><a href="{{ route('home') }}#how-it-works">{{ __('How It Works') }}</a></li>
                <li><a href="{{ route('home') }}#faq">{{ __('FAQs') }}</a></li>
                <li><a href="{{ route('about') }}" @if(request()->routeIs('about')) aria-current="page" @endif>{{ __('About') }}</a></li>
                <li><a href="{{ route('home') }}#contact">{{ __('Contact') }}</a></li>
            </ul>
            <div class="en-site-cta">
                @auth
                    <a class="btn btn-primary" href="{{ auth()->user()->landingUrl() }}">{{ __('Dashboard') }}</a>
                @else
                    <a class="btn btn-outline-primary" href="{{ route('login') }}">{{ __('Login') }}</a>
                    <a class="btn btn-primary" href="{{ route('register') }}">{{ __('Create Account') }}</a>
                @endauth
            </div>
        </div>
    </div>
</nav>
