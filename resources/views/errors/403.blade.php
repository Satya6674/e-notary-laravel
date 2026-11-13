@extends('layouts.app')

@section('title', __('You do not have access to this page.'))

@section('content')
<div class="en-error">
    <div class="en-error-code">403</div>
    <h1 class="h4 mt-3">{{ __('You do not have access to this page.') }}</h1>
    <a class="btn btn-primary mt-3" href="{{ auth()->check() ? auth()->user()->landingUrl() : route('home') }}">{{ __('Go Back') }}</a>
</div>
@endsection
