@extends('layouts.app')

@section('title', __('This request could not be completed.'))

@section('content')
<div class="en-error">
    <div class="en-error-code">{{ $exception->getStatusCode() }}</div>
    <h1 class="h4 mt-3">{{ __('This request could not be completed.') }}</h1>
    <a class="btn btn-primary mt-3" href="{{ auth()->check() ? auth()->user()->landingUrl() : route('home') }}">{{ __('Go Back') }}</a>
</div>
@endsection
