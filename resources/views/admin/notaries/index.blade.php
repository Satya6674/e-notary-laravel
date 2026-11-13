@extends('layouts.app')

@section('title', __('Manage Notaries'))

@section('content')
<x-ui.page-head :title="__('Manage Notaries')" :subtitle="__('Approve notary accounts. Only approved notaries can be assigned requests.')" />

<form method="GET" class="en-toolbar mb-3">
    <input type="text" name="search" class="form-control" placeholder="{{ __('Search by name, email, mobile') }}" aria-label="{{ __('Search by name, email, mobile') }}" value="{{ $search }}">
    <select name="status" class="form-select" aria-label="{{ __('Status') }}">
        <option value="all" @selected($status === 'all')>{{ __('All Status') }}</option>
        <option value="verified" @selected($status === 'verified')>{{ __('Approved') }}</option>
        <option value="pending" @selected($status === 'pending')>{{ __('Pending approval') }}</option>
        <option value="rejected" @selected($status === 'rejected')>{{ __('Rejected') }}</option>
    </select>
    <div class="en-toolbar-action"><button type="submit" class="btn btn-primary">{{ __('Filter') }}</button></div>
</form>

<x-ui.card flush>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>{{ __('Name') }}</th>
                    <th>{{ __('Email') }}</th>
                    <th>{{ __('Mobile') }}</th>
                    <th>{{ __('Email verified') }}</th>
                    <th>{{ __('Admin approval') }}</th>
                    <th>{{ __('Joined') }}</th>
                    <th class="text-end">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($notaries as $notary)
                    <tr>
                        <td>{{ $notary->name }}</td>
                        <td>{{ $notary->email }}</td>
                        <td class="text-nowrap">{{ $notary->mobile }}</td>
                        <td>
                            @if($notary->email_verified_at)
                                <span class="en-badge en-badge-approved">{{ __('Verified') }}</span>
                            @else
                                <span class="en-badge en-badge-pending">{{ __('Not yet') }}</span>
                            @endif
                        </td>
                        <td>
                            @if($notary->isDeactivated())
                                <span class="en-badge en-badge-rejected">{{ __('Deactivated') }}</span>
                            @elseif($notary->notary_approved_at)
                                <span class="en-badge en-badge-approved">{{ __('Approved') }}</span>
                            @elseif(in_array($notary->id, $rejectedIds, true))
                                <span class="en-badge en-badge-rejected">{{ __('Rejected') }}</span>
                            @else
                                <span class="en-badge en-badge-pending">{{ __('Pending') }}</span>
                            @endif
                        </td>
                        <td class="text-nowrap">{{ $notary->created_at?->ist('d M Y') }}</td>
                        <td>
                            <div class="en-actions">
                                @if(! $notary->notary_approved_at && $notary->isDeactivated())
                                    <span class="en-badge en-badge-pending">{{ __('Deactivated') }}</span>
                                @elseif(! $notary->notary_approved_at)
                                    <form action="{{ route('admin.notaries.approve', $notary) }}" method="POST" data-confirm="{{ __('Approve this notary?') }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-success">{{ __('Approve') }}</button>
                                    </form>
                                    @unless(in_array($notary->id, $rejectedIds, true))
                                        <form action="{{ route('admin.notaries.reject', $notary) }}" method="POST" data-confirm="{{ __('Reject this notary application? The applicant is told the reason you give.') }}" data-reason data-reason-min="10">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Reject') }}</button>
                                        </form>
                                    @endunless
                                @else
                                    <form action="{{ route('admin.notaries.reject', $notary) }}" method="POST" data-confirm="{{ __('Revoke approval for this notary? The notary is told the reason you give.') }}" data-reason data-reason-min="10">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Revoke') }}</button>
                                    </form>
                                @endif
                                <a href="{{ route('admin.users.show', $notary) }}" class="btn btn-sm btn-outline-primary">{{ __('View') }}</a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty colspan="7" icon="fa-user-tie">{{ __('No notaries found.') }}</x-ui.empty>
                @endforelse
            </tbody>
        </table>
    </div>
</x-ui.card>

@if($notaries instanceof \Illuminate\Contracts\Pagination\Paginator)
    <div class="mt-3">{{ $notaries->links() }}</div>
@endif
@endsection
