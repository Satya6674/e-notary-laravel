@extends('layouts.app')

@section('title', __($service['name']))

@section('content')
<x-ui.page-head :title="__($service['name'])" :subtitle="__($service['summary'])">
    <a class="btn btn-outline-primary" href="{{ route('user.services.index', ['service' => $service['slug']]) }}">{{ __('Back to Services') }}</a>
</x-ui.page-head>

@include('user.services.steps', ['step' => 2])

@if($other)
    <div class="en-notice mb-3">{{ __('Saving this application will replace your unsent application for :service.', ['service' => __($other['name'])]) }}</div>
@endif

<div class="row g-3">
    <div class="col-lg-8">
        <form id="service-form" action="{{ route('user.services.save', $service['slug']) }}" method="POST" enctype="multipart/form-data" novalidate>
            @csrf
            <x-ui.card :title="__('Application Details')" class="mb-3">
                <div class="en-notice mb-3">{{ __('Please use sample documents only. Do not upload real identity or legal documents. E-Notary does not provide legally recognised notarisation.') }}</div>

                @foreach($service['fields'] as $field)
                    @php($name = "details[{$field['key']}]")
                    @php($id = "f-{$field['key']}")
                    @php($dot = "details.{$field['key']}")
                    @php($value = old($dot, $draft?->details[$field['key']] ?? null))
                    <div class="mb-3">
                        <label class="form-label" for="{{ $id }}">{{ __($field['label']) }}@if($field['required']) <span class="en-required" aria-hidden="true">*</span>@endif</label>
                        @if($field['type'] === 'textarea')
                            <textarea id="{{ $id }}" name="{{ $name }}" rows="3" maxlength="{{ $field['max'] }}" class="form-control @error($dot) is-invalid @enderror" @required($field['required'])>{{ $value }}</textarea>
                        @else
                            <input id="{{ $id }}" name="{{ $name }}" type="{{ $field['type'] }}" value="{{ $value }}" @if($field['type'] === 'text') maxlength="{{ $field['max'] }}" @endif class="form-control @error($dot) is-invalid @enderror" @required($field['required'])>
                        @endif
                        @error($dot)<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                @endforeach

                <div class="mb-3">
                    <label class="form-label" for="f-purpose">{{ __('Purpose') }} <span class="en-required" aria-hidden="true">*</span></label>
                    <textarea id="f-purpose" name="purpose" rows="3" maxlength="2000" class="form-control @error('purpose') is-invalid @enderror" required>{{ old('purpose', $draft?->details['purpose'] ?? null) }}</textarea>
                    @error('purpose')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label class="form-label" for="f-notes">{{ __('Optional Notes') }}</label>
                    <textarea id="f-notes" name="notes" rows="2" maxlength="2000" class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $draft?->details['notes'] ?? null) }}</textarea>
                    @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </x-ui.card>

            <x-ui.card :title="__('Documents')" class="mb-3">
                @foreach($service['documents'] as $document)
                    @php($key = $document['key'])
                    @php($file = $draft?->file($key))
                    @php($rejected = $errors->has("files.{$key}"))
                    <div class="en-upload">
                        <div class="en-upload-head">
                            <label class="form-label mb-0" for="file-{{ $key }}">{{ __($document['name']) }}</label>
                            <span class="en-doc-tag {{ $document['required'] ? 'is-required' : 'is-optional' }}">{{ __($document['required'] ? 'Required' : 'Optional') }}</span>
                            @if($rejected)
                                <span class="en-doc-tag is-rejected">{{ __('Rejected') }}</span>
                            @elseif($file)
                                <span class="en-doc-tag is-uploaded">{{ __('File uploaded') }}</span>
                            @else
                                <span class="en-doc-tag is-pending">{{ __('Pending') }}</span>
                            @endif
                        </div>
                        <input id="file-{{ $key }}" type="file" name="files[{{ $key }}]" class="form-control @error("files.{$key}") is-invalid @enderror" accept=".{{ implode(',.', $document['formats']) }}">
                        <div class="form-text">{{ strtoupper(implode(', ', $document['formats'])) }}, {{ __('up to :size MB.', ['size' => round($document['max_kb'] / 1024, 1)]) }}</div>
                        @error("files.{$key}")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        @if($file)
                            <div class="en-upload-file">
                                <span class="text-break"><i class="fas fa-paperclip me-2" aria-hidden="true"></i>{{ $file['name'] }}</span>
                                <button class="btn btn-sm btn-outline-secondary" type="submit" form="remove-{{ $key }}">{{ __('Remove') }}</button>
                            </div>
                            <div class="form-text">{{ __('Choose a new file to replace this one.') }}</div>
                        @endif
                    </div>
                @endforeach
            </x-ui.card>

            <button type="submit" class="btn btn-primary">{{ __('Save and Review') }}</button>
        </form>

        @if($draft)
            @foreach($service['documents'] as $document)
                @if($draft->file($document['key']))
                    <form id="remove-{{ $document['key'] }}" method="POST" action="{{ route('user.services.documents.destroy', [$service['slug'], $document['key']]) }}">
                        @csrf
                        @method('DELETE')
                    </form>
                @endif
            @endforeach
        @endif
    </div>

    <div class="col-lg-4">
        @include('partials.service-requirements', ['service' => $service, 'draft' => $draft])
    </div>
</div>
@endsection
