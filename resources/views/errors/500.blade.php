@extends('layouts.app')

@section('title', __('Something went wrong on our side. Please try again.'))

@section('content')
<div class="en-error">
    <div class="en-error-code">500</div>
    <h1 class="h4 mt-3">{{ __('Something went wrong on our side. Please try again.') }}</h1>
    <a class="btn btn-primary mt-3" href="{{ auth()->check() ? auth()->user()->landingUrl() : route('home') }}">{{ __('Go Back') }}</a>
</div>
@endsection
