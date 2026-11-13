@extends('layouts.app')

@section('title', __('Deleted Users'))

@section('content')
@php
    $roleLabels = ['public' => __('Citizen'), 'notary' => __('Notary'), 'admin' => __('Admin')];
    $noticeLabels = ['queued' => [__('Queued'), 'en-badge-pending'], 'sent' => [__('Sent'), 'en-badge-approved'], 'failed' => [__('Failed'), 'en-badge-rejected'], 'blocked' => [__('Blocked'), 'en-badge-rejected'], 'not_queued' => [__('Not queued'), 'en-badge-rejected'], 'suppressed' => [__('Not needed: replaced by recovery'), 'en-badge-pending']];
    // The password email of a plain restore. "recovery" and "superseded" rows are shown elsewhere.
    $resetLabels = ['queued' => [__('Queued'), 'en-badge-pending'], 'sent' => [__('Sent'), 'en-badge-approved'], 'failed' => [__('Failed'), 'en-badge-rejected'], 'blocked' => [__('Blocked'), 'en-badge-rejected'], 'not_queued' => [__('Not queued'), 'en-badge-rejected'], 'expired' => [__('Expired'), 'en-badge-rejected'], 'untracked' => [__('Not tracked'), 'en-badge-pending'], 'used' => [__('Used: new password set'), 'en-badge-approved']];
@endphp

<x-ui.page-head :title="__('Deleted Users')" :subtitle="__('Applications, documents and audit history are kept. A restored account returns as it was.')" />

<p><a href="{{ route('admin.users.index') }}" class="btn btn-sm btn-outline-secondary">{{ __('Back to Users') }}</a></p>

<x-ui.card flush>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>{{ __('Reference') }}</th>
                    <th>{{ __('User') }}</th>
                    <th>{{ __('Role') }}</th>
                    <th>{{ __('Deleted') }}</th>
                    <th>{{ __('Reason') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th>{{ __('Email status') }}</th>
                    <th class="text-end">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($deletions as $deletion)
                    @php($notices = array_filter([\App\Models\AccountDeletion::NOTICE_DELETION => __('Deletion'), \App\Models\AccountDeletion::NOTICE_RESTORE => $deletion->isActive() ? null : __('Restore')]))
                    <tr>
                        <td class="text-nowrap">{{ $deletion->reference }}</td>
                        <td>
                            {{ $deletion->name }}<span class="en-meta d-block">{{ $deletion->email }}</span>
                            @if($deletion->isActive())
                                <span class="en-meta d-block">{{ $deletion->identity_released_at ? __('Email and mobile released') : __('Email and mobile kept') }}</span>
                            @endif
                        </td>
                        <td>{{ $roleLabels[$deletion->role] ?? $deletion->role }}</td>
                        <td class="text-nowrap">{{ $deletion->deleted_at->ist('d M Y, h:i A') }}<span class="en-meta d-block">{{ __('by :name', ['name' => $deletion->deletedBy?->name]) }}</span></td>
                        <td>{{ $deletion->reason }}</td>
                        <td>
                            @if($deletion->isActive())
                                <span class="en-badge en-badge-rejected">{{ __('Deleted') }}</span>
                            @else
                                <span class="en-badge en-badge-approved">{{ __('Restored') }}</span>
                                <span class="en-meta d-block">{{ $deletion->restored_at->ist('d M Y, h:i A') }}</span>
                            @endif
                        </td>
                        <td>
                            @foreach($notices as $notice => $label)
                                @php([$text, $badge] = $noticeLabels[$deletion->noticeStatus($notice)])
                                <div class="mb-1">
                                    <span class="en-meta">{{ $label }}:</span> <span class="en-badge {{ $badge }}">{{ $text }}</span>
                                    @if($deletion->canRetry($notice))
                                        <form action="{{ route('admin.users.deletion.retry', [$deletion, $notice]) }}" method="POST" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-link p-0">{{ __('Retry') }}</button>
                                        </form>
                                    @endif
                                </div>
                            @endforeach
                            @unless($deletion->isActive())
                                @php($link = app(\App\Services\AccountDeletionService::class)->resetLinkState($deletion))
                                @if(isset($resetLabels[$link['status']]))
                                    @php([$text, $badge] = $resetLabels[$link['status']])
                                    <div class="mb-1">
                                        <span class="en-meta">{{ __('Password link') }}:</span> <span class="en-badge {{ $badge }}">{{ $text }}</span>
                                        {{-- A running worker renews this heartbeat every loop (AppServiceProvider). --}}
                                        @if($link['status'] === 'queued' && ! \Illuminate\Support\Facades\Cache::has(\App\Support\Platform::WORKER_HEARTBEAT))
                                            <span class="en-meta d-block">{{ __('Waits for the queue worker, which is not running. The link expires 60 minutes after it was made.') }}</span>
                                        @endif
                                        @if($link['resendable'])
                                            <form action="{{ route('admin.users.deletion.reset-link', $deletion) }}" method="POST" class="d-inline" data-confirm="{{ __('Send a new password link to the registered address? The earlier link stops working. The account stays locked until the person sets a new password.') }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-link p-0">{{ __('Send a new link') }}</button>
                                            </form>
                                        @endif
                                    </div>
                                @endif
                            @endunless
                        </td>
                        <td>
                            <div class="en-actions">
                                @if($deletion->isActive())
                                    <form action="{{ route('admin.users.restore', $deletion->user_id) }}" method="POST" data-confirm="{{ __('Restore this account? Its old password stops working: the person sets a new one from an emailed link and verifies their mobile number again. A Notary also needs your approval again.') }}" data-reason data-reason-min="10">
                                        @csrf
                                        @if($rejectedRecovery->has($deletion->id))
                                            {{-- A rejected recovery was a decision about this person: overriding it is deliberate. --}}
                                            <div class="form-check small mb-1">
                                                <input class="form-check-input" type="checkbox" name="rejection_reviewed" value="1" id="reviewed-{{ $deletion->id }}" required>
                                                <label class="form-check-label" for="reviewed-{{ $deletion->id }}">{{ __('A recovery request for this account was rejected. I have reviewed that decision.') }}</label>
                                            </div>
                                        @endif
                                        <button type="submit" class="btn btn-sm btn-outline-success">{{ __('Restore') }}</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty colspan="8" icon="fa-users">{{ __('No deleted users.') }}</x-ui.empty>
                @endforelse
            </tbody>
        </table>
    </div>
</x-ui.card>

<div class="mt-3">{{ $deletions->links() }}</div>
<p class="en-meta mt-2">{{ __('Sent means the mail service accepted the email. It does not prove that it arrived.') }}</p>
@endsection
