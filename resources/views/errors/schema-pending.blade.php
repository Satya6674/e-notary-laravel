@extends('layouts.app')

@section('title', __('Update Pending'))

@section('content')
<div class="en-verify-card">
    <div class="card"><div class="card-body text-center">
        <h1 class="h4">{{ __('Update Pending') }}</h1>
        <p class="text-muted mb-0">{{ __('This portal is waiting for an approved database update. Your data is safe. Please try again after the update has been applied.') }}</p>
    </div></div>
</div>
@endsection
