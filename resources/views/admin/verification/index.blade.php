@extends('layouts.app')

@section('title', __('User Verification Management'))

@section('content')
@php
    $roleLabels = ['public' => __('Citizen'), 'notary' => __('Notary')];
@endphp

<x-ui.page-head :title="__('User Verification Management')" :subtitle="__('Help Citizens and Notaries who cannot complete email or mobile verification. Contact details are shown masked.')" />

@unless($supported)
    <div class="alert alert-warning" role="status">{{ __('Manual verification and temporary access need a database update (migrations 000029 and 000030) that has not been applied yet. The list below is read-only.') }}</div>
@endunless

<form method="GET" class="en-toolbar mb-3">
    <input type="text" name="search" class="form-control" placeholder="{{ __('Search by name, email, mobile') }}" aria-label="{{ __('Search by name, email, mobile') }}" value="{{ $search }}">
    <select name="role" class="form-select" aria-label="{{ __('Role') }}">
        <option value="all" @selected($role === 'all')>{{ __('Citizens and Notaries') }}</option>
        <option value="public" @selected($role === 'public')>{{ __('Citizen') }}</option>
        <option value="notary" @selected($role === 'notary')>{{ __('Approved Notary') }}</option>
        <option value="pending_notary" @selected($role === 'pending_notary')>{{ __('Notary awaiting approval') }}</option>
    </select>
    <select name="state" class="form-select" aria-label="{{ __('Status') }}">
        <option value="all" @selected($state === 'all')>{{ __('All Status') }}</option>
        <option value="email_pending" @selected($state === 'email_pending')>{{ __('Email pending') }}</option>
        <option value="mobile_pending" @selected($state === 'mobile_pending')>{{ __('Mobile not verified by SMS') }}</option>
        <option value="temporary_access" @selected($state === 'temporary_access')>{{ __('Temporary Access Active') }}</option>
    </select>
    <div class="en-toolbar-action"><button type="submit" class="btn btn-primary">{{ __('Filter') }}</button></div>
</form>

<x-ui.card flush>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>{{ __('ID') }}</th>
                    <th>{{ __('Name') }}</th>
                    <th>{{ __('Role') }}</th>
                    <th>{{ __('Email') }}</th>
                    <th>{{ __('Mobile') }}</th>
                    <th>{{ __('Temporary access') }}</th>
                    <th class="text-end">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($users as $account)
                    @php($badges = \App\Support\VerificationStatus::badges($account))
                    <tr>
                        <td>{{ $account->id }}</td>
                        <td>{{ $account->name }}</td>
                        <td>
                            {{ $roleLabels[$account->role] ?? $account->role }}
                            @if($account->isNotary() && ! $account->notary_approved_at)
                                <span class="en-meta d-block">{{ __('Awaiting approval') }}</span>
                            @endif
                            @if($account->deactivated_at)
                                <span class="en-badge en-badge-rejected">{{ __('Deactivated') }}</span>
                            @endif
                        </td>
                        <td>
                            <span class="d-block en-meta">{{ \App\Services\DeliveryLog::maskEmail($account->email) }}</span>
                            <span class="en-badge {{ $badges['email'][1] }}">{{ __($badges['email'][0]) }}</span>
                        </td>
                        <td>
                            <span class="d-block en-meta">{{ filled($account->mobile) ? \App\Services\SmsService::mask($account->mobile) : '-' }}</span>
                            <span class="en-badge {{ $badges['mobile'][1] }}">{{ __($badges['mobile'][0]) }}</span>
                        </td>
                        <td>
                            @if($badges['access'])
                                <span class="en-badge {{ $badges['access'][1] }}">{{ __($badges['access'][0]) }}</span>
                            @else
                                <span class="en-meta">-</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <a href="{{ route('admin.verification.show', $account) }}" class="btn btn-sm btn-outline-primary">{{ __('Manage') }}</a>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty colspan="7">{{ __('No accounts match these filters.') }}</x-ui.empty>
                @endforelse
            </tbody>
        </table>
    </div>
</x-ui.card>

<div class="mt-3">{{ $users->links() }}</div>
@endsection
