@extends('layouts.app')

@section('title', __('Privacy Center'))

@section('content')
<x-ui.page-head :title="__('Privacy Center')" :subtitle="__('See your data and make a request about it.')">
    <a class="btn btn-outline-primary" href="{{ route('privacy-center.export') }}"><i class="fas fa-download me-2" aria-hidden="true"></i>{{ __('Download My Data') }}</a>
</x-ui.page-head>

<x-ui.card :title="__('Your Consent')" class="mb-3">
    @if($consentCurrent)
        <p class="mb-2"><span class="en-badge en-badge-approved">{{ __('Accepted') }}</span>
            {{ __('You have accepted the current Terms of Use and Privacy Policy.') }}</p>
    @else
        <div class="alert alert-warning" role="status">
            {{ $consents->isEmpty() ? __('We have no record of your acceptance of the Terms of Use and Privacy Policy.') : __('The Terms of Use or Privacy Policy have changed since you last accepted them.') }}
        </div>
        <form method="POST" action="{{ route('privacy-center.consent') }}" class="mb-3">
            @csrf
            <div class="form-check mb-2">
                <input class="form-check-input @error('accept_terms') is-invalid @enderror" type="checkbox" name="accept_terms" id="accept_terms" value="1" required @error('accept_terms') aria-describedby="accept-terms-error" @enderror>
                <label class="form-check-label" for="accept_terms">{{ __('I have read and accept the') }} <a href="{{ route('terms') }}" target="_blank" rel="noopener">{{ __('Terms of Use') }}</a> {{ __('and') }} <a href="{{ route('privacy') }}" target="_blank" rel="noopener">{{ __('Privacy Policy') }}</a>.</label>
                @error('accept_terms')<div id="accept-terms-error" class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <button class="btn btn-primary btn-sm" type="submit">{{ __('Record my acceptance') }}</button>
        </form>
    @endif
    <p class="en-meta mb-2">{{ __('Current versions') }}: {{ __('Terms of Use') }} {{ $versions['terms_version'] }}, {{ __('Privacy Policy') }} {{ $versions['privacy_version'] }}.
        {{ __('To withdraw your consent, ask for your data to be deleted below.') }}</p>
    @if($consents->isNotEmpty())
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <caption class="visually-hidden">{{ __('Consent history') }}</caption>
                <thead><tr><th scope="col">{{ __('Accepted') }} (IST)</th><th scope="col">{{ __('Terms of Use') }}</th><th scope="col">{{ __('Privacy Policy') }}</th><th scope="col">{{ __('Where') }}</th></tr></thead>
                <tbody>
                    @foreach($consents as $entry)
                        <tr>
                            <td class="text-nowrap">{{ $entry->created_at?->ist('d M Y, H:i') }}</td>
                            <td>{{ $entry->meta['terms_version'] ?? '-' }}</td>
                            <td>{{ $entry->meta['privacy_version'] ?? '-' }}</td>
                            <td>{{ __(['registration' => 'Sign-up', 'privacy_center' => 'Privacy Center'][$entry->meta['source'] ?? ''] ?? '-') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-ui.card>

<div class="row g-3">
    <div class="col-lg-5">
        <x-ui.card :title="__('Make a Request')">
            <form method="POST" action="{{ route('privacy-center.store') }}">
                @csrf
                <div class="mb-3">
                    <label class="form-label" for="type">{{ __('Request Type') }}</label>
                    <select id="type" name="type" class="form-select">
                        @foreach(['access' => 'A copy of my data', 'correction' => 'Correct my data', 'deletion' => 'Delete my data', 'objection' => 'Object to how my data is used'] as $value => $label)
                            <option value="{{ $value }}">{{ __($label) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="details">{{ __('Details') }}</label>
                    <textarea id="details" name="details" class="form-control" rows="3" maxlength="1000">{{ old('details') }}</textarea>
                </div>
                <button class="btn btn-primary" type="submit">{{ __('Send Request') }}</button>
                <p class="en-meta mt-2 mb-0">{{ __('An administrator reviews each request. Some records may have to be kept, and you will be told the outcome here.') }}</p>
            </form>
        </x-ui.card>
    </div>
    <div class="col-lg-7">
        <x-ui.card :title="__('My Requests')" flush>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Type') }}</th><th>{{ __('Status') }}</th><th>{{ __('Outcome') }}</th></tr></thead>
                    <tbody>
                        @forelse($requests as $item)
                            <tr>
                                <td class="text-nowrap">{{ $item->created_at?->ist('d M Y') }}</td>
                                <td>{{ __(ucfirst($item->type)) }}</td>
                                <td>{{ __(ucwords(str_replace('_', ' ', $item->status))) }}</td>
                                <td class="small">{{ $item->resolution ?? '-' }}</td>
                            </tr>
                        @empty
                            <x-ui.empty colspan="4" icon="fa-user-shield">{{ __('No requests yet.') }}</x-ui.empty>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>
</div>
@endsection
