@extends('layouts.app')

@section('title', __('Account Recovery Requests'))

@section('content')
@php
    $roleLabels = ['public' => __('Citizen'), 'notary' => __('Notary'), 'admin' => __('Admin')];
    $statusLabels = ['pending' => [__('Pending'), 'en-badge-pending'], 'under_review' => [__('Under Review'), 'en-badge-pending'], 'approved' => [__('Approved'), 'en-badge-approved'], 'rejected' => [__('Rejected'), 'en-badge-rejected'], 'cancelled' => [__('Cancelled'), 'en-badge-rejected'], 'expired' => [__('Expired'), 'en-badge-rejected']];
@endphp

<x-ui.page-head :title="__('Account Recovery Requests')" :subtitle="__('Nothing is restored until you approve it here.')" />

<form method="GET" class="en-toolbar mb-3">
    <select name="status" class="form-select" aria-label="{{ __('Status') }}">
        <option value="all" @selected($status === 'all')>{{ __('All Status') }}</option>
        @foreach($statusLabels as $value => [$label])
            <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
        @endforeach
    </select>
    <div class="en-toolbar-action"><button type="submit" class="btn btn-primary">{{ __('Filter') }}</button></div>
</form>

<x-ui.card flush>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>{{ __('Reference') }}</th>
                    <th>{{ __('Applicant') }}</th>
                    <th>{{ __('Verified contact') }}</th>
                    <th>{{ __('Old account ID') }}</th>
                    <th>{{ __('Role') }}</th>
                    <th>{{ __('Requested') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th class="text-end">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($requests as $item)
                    @php([$label, $badge] = $statusLabels[$item->status])
                    <tr>
                        <td class="text-nowrap">{{ $item->reference }}</td>
                        <td>{{ $item->applicant_name }}</td>
                        <td>{{ \App\Services\DeliveryLog::maskEmail($item->verified_email) }}<span class="en-meta d-block">{{ $item->verified_mobile ? \App\Services\SmsService::mask($item->verified_mobile) : '' }}</span></td>
                        <td>{{ $item->old_user_id }}</td>
                        <td>{{ $roleLabels[$item->deletion->role] ?? $item->deletion->role }}</td>
                        <td class="text-nowrap">{{ $item->created_at?->ist('d M Y, h:i A') }}</td>
                        <td><span class="en-badge {{ $badge }}">{{ $label }}</span></td>
                        <td><div class="en-actions"><a href="{{ route('admin.recovery.show', $item) }}" class="btn btn-sm btn-outline-primary">{{ __('Review') }}</a></div></td>
                    </tr>
                @empty
                    <x-ui.empty colspan="8" icon="fa-user-shield">{{ __('No recovery requests.') }}</x-ui.empty>
                @endforelse
            </tbody>
        </table>
    </div>
</x-ui.card>

<div class="mt-3">{{ $requests->links() }}</div>
@endsection
