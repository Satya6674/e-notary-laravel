@extends('layouts.app')

@section('title', __('Review Your Application'))

@section('content')
@php($blocked = $missing || $missingFields)
<x-ui.page-head :title="__('Review Your Application')" :subtitle="__($service['name'])">
    <a class="btn btn-outline-primary" href="{{ route('user.services.apply', $service['slug']) }}">{{ __('Edit') }}</a>
</x-ui.page-head>

@include('user.services.steps', ['step' => 3])

@if($blocked)
    <div class="alert alert-danger" role="alert">
        <strong>{{ __('This application cannot be submitted yet.') }}</strong>
        <ul class="mb-0 mt-2">
            @foreach($missingFields as $field)<li>{{ __(':item is missing.', ['item' => __($field['label'])]) }}</li>@endforeach
            @foreach($missing as $document)<li>{{ __(':item is missing.', ['item' => __($document['name'])]) }}</li>@endforeach
        </ul>
    </div>
@endif

<div class="row g-3">
    <div class="col-lg-7">
        <x-ui.card :title="__('Applicant')" class="mb-3">
            <dl class="row mb-0">
                <dt class="col-sm-4">{{ __('Name') }}</dt><dd class="col-sm-8">{{ auth()->user()->name }}</dd>
                <dt class="col-sm-4">{{ __('Email') }}</dt><dd class="col-sm-8 text-break">{{ auth()->user()->email }}</dd>
            </dl>
        </x-ui.card>

        <x-ui.card :title="__('Application Details')" class="mb-3">
            <dl class="row mb-0">
                <dt class="col-sm-4">{{ __('Service') }}</dt><dd class="col-sm-8">{{ __($service['name']) }}</dd>
                @foreach($service['fields'] as $field)
                    @if(filled($draft->details[$field['key']] ?? null))
                        <dt class="col-sm-4">{{ __($field['label']) }}</dt><dd class="col-sm-8 text-break">{{ $draft->details[$field['key']] }}</dd>
                    @endif
                @endforeach
                <dt class="col-sm-4">{{ __('Purpose') }}</dt><dd class="col-sm-8 text-break">{{ $draft->details['purpose'] ?? '' }}</dd>
                @if(filled($draft->details['notes'] ?? null))
                    <dt class="col-sm-4">{{ __('Notes') }}</dt><dd class="col-sm-8 text-break">{{ $draft->details['notes'] }}</dd>
                @endif
            </dl>
        </x-ui.card>
    </div>

    <div class="col-lg-5">
        <x-ui.card :title="__('Documents')" class="mb-3">
            <ul class="en-req-list">
                @foreach($service['documents'] as $document)
                    @php($file = $draft->file($document['key']))
                    <li>
                        <span>
                            {{ __($document['name']) }}
                            @if($file)<small class="d-block text-muted text-break">{{ $file['name'] }}</small>@endif
                        </span>
                        @if($file)
                            <span class="en-doc-tag is-uploaded">{{ __('File uploaded') }}</span>
                        @elseif($document['required'])
                            <span class="en-doc-tag is-rejected">{{ __('Missing') }}</span>
                        @else
                            <span class="en-doc-tag is-optional">{{ __('Optional') }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-ui.card>

        <x-ui.card :title="__('Declaration')">
            <form method="POST" action="{{ route('user.services.submit', $service['slug']) }}" id="submit-form">
                @csrf
                <div class="form-check mb-3">
                    <input id="declaration" class="form-check-input @error('declaration') is-invalid @enderror" type="checkbox" name="declaration" value="1" required>
                    <label class="form-check-label" for="declaration">{{ __('I confirm these details are accurate and these are sample documents only. I understand E-Notary provides no legally recognised notarisation.') }}</label>
                    @error('declaration')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <button class="btn btn-primary w-100" type="submit" @disabled($blocked)>{{ __('Submit Application') }}</button>
            </form>
            <form method="POST" action="{{ route('user.services.discard') }}" class="mt-2">
                @csrf
                @method('DELETE')
                <button class="btn btn-outline-secondary w-100" type="submit">{{ __('Discard Draft') }}</button>
            </form>
        </x-ui.card>
    </div>
</div>
@endsection
