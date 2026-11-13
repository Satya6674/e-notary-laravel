@extends('layouts.app')

@section('title', __($service['name']))

@section('fullwidth', true)

@section('content')
@php($user = auth()->user())
@php($citizen = $user?->role === 'public')
<section class="en-page-hero">
    <div class="en-wrap">
        <nav aria-label="{{ __('Breadcrumb') }}" class="en-crumb"><a href="{{ route('services.index') }}">{{ __('Services') }}</a> <span aria-hidden="true">/</span> <a href="{{ route('services.index', ['category' => $service['category']]) }}">{{ __(config("service_catalogue.categories.{$service['category']}.name")) }}</a></nav>
        <h1>{{ __($service['name']) }}</h1>
        <p>{{ __($service['summary']) }}</p>
    </div>
</section>

<section class="en-section">
    <div class="en-wrap en-narrow">
        @component('partials.service-requirements', ['service' => $service])
            <div class="en-req-cta">
                @if($canApply)
                    @if($citizen)
                        <a class="btn btn-primary" href="{{ route('user.services.index', ['service' => $service['slug']]) }}">{{ __('Continue to Application') }}</a>
                    @elseif(! $user)
                        <a class="btn btn-primary" href="{{ route('register') }}">{{ __('Create Account') }}</a>
                        <a class="btn btn-outline-primary" href="{{ route('login') }}">{{ __('Login') }}</a>
                    @else
                        <p class="en-meta mb-0">{{ __('Citizen accounts apply for this service.') }}</p>
                    @endif
                @elseif($service['open'])
                    @if($citizen)
                        <a class="btn btn-primary" href="{{ route($service['destination']) }}">{{ __('Open') }}</a>
                    @elseif(! $user)
                        <a class="btn btn-primary" href="{{ route('login') }}">{{ __('Login to Use This Service') }}</a>
                    @endif
                @else
                    <a class="btn btn-outline-primary" href="{{ route('services.index') }}">{{ __('View All Services') }}</a>
                @endif
            </div>
        @endcomponent
    </div>
</section>
@endsection
