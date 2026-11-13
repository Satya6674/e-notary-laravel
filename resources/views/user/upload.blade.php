@extends('layouts.app')

@section('title', __('Upload Document'))

@section('content')
<x-ui.page-head :title="__('Upload Document')" />

<div class="row">
    <div class="col-lg-8">
        <x-ui.card>
            <form action="{{ route('user.upload.store') }}" method="POST" enctype="multipart/form-data">
                @csrf

                <div class="en-notice mb-3">{{ __('Please use sample documents only. Do not upload real identity or legal documents. E-Notary does not provide legally recognised notarisation.') }}</div>

                <x-ui.field name="title" :label="__('Document Title')" required />

                <div class="mb-3">
                    <label class="form-label" for="f-document_type">{{ __('Document Type') }} <span class="en-required" aria-hidden="true">*</span></label>
                    <select id="f-document_type" name="document_type" class="form-select @error('document_type') is-invalid @enderror" required>
                        <option value="">{{ __('Select type') }}</option>
                        <option value="affidavit" @selected(old('document_type') === 'affidavit')>{{ __('Affidavit') }}</option>
                        <option value="power_of_attorney" @selected(old('document_type') === 'power_of_attorney')>{{ __('Power of Attorney') }}</option>
                        <option value="sale_deed" @selected(old('document_type') === 'sale_deed')>{{ __('Sale Deed') }}</option>
                        <option value="rent_agreement" @selected(old('document_type') === 'rent_agreement')>{{ __('Rent Agreement') }}</option>
                        <option value="will" @selected(old('document_type') === 'will')>{{ __('Will') }}</option>
                        <option value="declaration" @selected(old('document_type') === 'declaration')>{{ __('Declaration') }}</option>
                        <option value="undertaking" @selected(old('document_type') === 'undertaking')>{{ __('Undertaking') }}</option>
                        <option value="indemnity_bond" @selected(old('document_type') === 'indemnity_bond')>{{ __('Indemnity Bond') }}</option>
                        <option value="other" @selected(old('document_type') === 'other')>{{ __('Other') }}</option>
                    </select>
                    @error('document_type')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label class="form-label" for="f-description">{{ __('Description') }} <span class="en-required" aria-hidden="true">*</span></label>
                    <textarea id="f-description" name="description" class="form-control @error('description') is-invalid @enderror" rows="3" required>{{ old('description') }}</textarea>
                    @error('description')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label class="form-label" for="f-file">{{ __('Upload File') }} <span class="en-required" aria-hidden="true">*</span></label>
                    <input id="f-file" type="file" name="file" class="form-control @error('file') is-invalid @enderror" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required>
                    <div class="form-text">{{ __('PDF, JPG, PNG, DOC or DOCX, up to 10 MB. Sample documents only.') }}</div>
                    <div class="form-text">{{ __('PDF, JPG and PNG files can be previewed. Word files can be downloaded.') }}</div>
                    @error('file')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label class="form-label" for="f-notes">{{ __('Optional Notes') }}</label>
                    <textarea id="f-notes" name="notes" class="form-control @error('notes') is-invalid @enderror" rows="2">{{ old('notes') }}</textarea>
                    @error('notes')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <button type="submit" class="btn btn-primary">{{ __('Submit') }}</button>
            </form>
        </x-ui.card>
    </div>
</div>
@endsection
