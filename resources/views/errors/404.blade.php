@extends('layouts.app')

@section('title', __('This page could not be found.'))

@section('content')
<div class="en-error">
    <div class="en-error-code">404</div>
    <h1 class="h4 mt-3">{{ __('This page could not be found.') }}</h1>
    <a class="btn btn-primary mt-3" href="{{ auth()->check() ? auth()->user()->landingUrl() : route('home') }}">{{ __('Go Back') }}</a>
</div>
@endsection
