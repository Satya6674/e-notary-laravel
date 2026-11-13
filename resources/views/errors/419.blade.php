@extends('layouts.app')

@section('title', __('This page expired. Please go back and try again.'))

@section('content')
<div class="en-error">
    <div class="en-error-code">419</div>
    <h1 class="h4 mt-3">{{ __('This page expired. Please go back and try again.') }}</h1>
    <a class="btn btn-primary mt-3" href="{{ auth()->check() ? auth()->user()->landingUrl() : route('home') }}">{{ __('Go Back') }}</a>
</div>
@endsection
