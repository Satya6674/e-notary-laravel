@extends('layouts.app')

@section('title', __('Replace lost email address'))

@section('content')
@php
    $checkLabels = ['video_call' => __('Video call'), 'in_person' => __('In person'), 'phone_call' => __('Phone call to a number on record'), 'other' => __('Other (describe below)')];
    $statusLabels = ['pending' => [__('Waiting for the new address'), 'en-badge-pending'], 'verified' => [__('Confirmed'), 'en-badge-approved'], 'cancelled' => [__('Cancelled'), 'en-badge-rejected'], 'expired' => [__('Expired'), 'en-badge-rejected']];
@endphp

<x-ui.page-head :title="__('Replace lost email address')">
    <a href="{{ route('admin.users.show', $user) }}" class="btn btn-outline-primary"><i class="fas fa-arrow-left me-2" aria-hidden="true"></i>{{ __('Back to User Details') }}</a>
</x-ui.page-head>

<div class="row">
    <div class="col-lg-8">
        <x-ui.card class="mb-3">
            <p>{{ __('Use this only after the person asked Support and you checked their identity outside the system. The account stays locked: nothing changes until the new address opens the link we send it, and then it receives the password link.') }}</p>
            <p class="en-meta">{{ __('Account') }}: #{{ $user->id }} {{ $user->name }} &middot; {{ __('Current address') }}: {{ \App\Services\DeliveryLog::maskEmail($user->email) }}</p>

            <form method="POST" action="{{ route('admin.users.email-replacement.store', $user) }}">
                @csrf
                <x-ui.field name="new_email" type="email" :label="__('New email address')" :value="old('new_email')" required />

                <div class="row">
                    <div class="col-sm-6"><x-ui.field name="support_ticket_id" type="number" :label="__('Support ticket ID')" :value="old('support_ticket_id', request('ticket'))" /></div>
                    <div class="col-sm-6"><x-ui.field name="contact_message_id" type="number" :label="__('or contact message ID')" :value="old('contact_message_id')" /></div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="f-check">{{ __('How you checked their identity') }} <span class="en-required" aria-hidden="true">*</span></label>
                    <select id="f-check" name="identity_check" class="form-select @error('identity_check') is-invalid @enderror" required>
                        @foreach($checkLabels as $value => $label)
                            <option value="{{ $value }}" @selected(old('identity_check') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('identity_check')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <x-ui.field name="identity_note" :label="__('What you checked')" :hint="__('A short description. Never record document or ID numbers, and do not upload documents.')" required />
                <x-ui.field name="reason" :label="__('Reason')" :hint="__('At least 20 characters.')" required />

                <div class="form-check mb-3">
                    <input class="form-check-input @error('identity_confirmed') is-invalid @enderror" type="checkbox" name="identity_confirmed" value="1" id="f-confirmed" required>
                    <label class="form-check-label" for="f-confirmed">{{ __('I checked this person\'s identity outside the system.') }}</label>
                    @error('identity_confirmed')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <button type="submit" class="btn btn-primary">{{ __('Send the confirmation link') }}</button>
            </form>
        </x-ui.card>
    </div>

    <div class="col-lg-4">
        <x-ui.card :title="__('History')">
            @forelse($replacements as $item)
                @php([$label, $badge] = $statusLabels[$item->status])
                <div class="mb-3">
                    <strong>{{ $item->reference }}</strong> <span class="en-badge {{ $badge }}">{{ $label }}</span>
                    <span class="en-meta d-block">{{ __('To') }} {{ \App\Services\DeliveryLog::maskEmail($item->new_email) }} &middot; {{ $item->created_at->ist('d M Y, h:i A') }}</span>
                    @if($item->isPending())
                        <form method="POST" action="{{ route('admin.email-replacements.cancel', $item) }}" class="mt-1" data-confirm="{{ __('Cancel this replacement? Its link stops working.') }}" data-reason data-reason-min="10">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Cancel') }}</button>
                        </form>
                    @endif
                </div>
            @empty
                <p class="en-meta mb-0">{{ __('None yet.') }}</p>
            @endforelse
        </x-ui.card>
    </div>
</div>
@endsection
