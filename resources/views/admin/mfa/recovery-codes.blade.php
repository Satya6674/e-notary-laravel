@extends('layouts.app')

@section('title', __('Recovery codes'))

@section('content')
<x-ui.page-head :title="__('Recovery codes')" />

<div class="row">
    <div class="col-lg-6">
        <x-ui.card>
            @if($fresh)
                <p><span class="en-badge en-badge-approved">{{ __('On') }}</span> {{ __('Two-factor sign-in is now on.') }}</p>
            @endif
            <p>{{ __('Save these recovery codes somewhere safe, such as a password manager. Each one works once, if your phone is not available. They are shown only now.') }}</p>
            <ul class="list-unstyled mb-3" aria-label="{{ __('Recovery codes') }}">
                @foreach($codes as $code)
                    <li><code class="fs-5">{{ $code }}</code></li>
                @endforeach
            </ul>
            <a href="{{ route('admin.mfa.show') }}" class="btn btn-primary">{{ __('I have saved them') }}</a>
        </x-ui.card>
    </div>
</div>
@endsection
