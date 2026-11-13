@extends('layouts.app')

@section('title', $recovery->reference)

@section('content')
@php
    $roleLabels = ['public' => __('Citizen'), 'notary' => __('Notary'), 'admin' => __('Admin')];
    $statusLabels = ['pending' => __('Pending'), 'under_review' => __('Under Review'), 'approved' => __('Approved'), 'rejected' => __('Rejected'), 'cancelled' => __('Cancelled'), 'expired' => __('Expired')];
    $proof = [
        'emailed_code' => __('Proven: typed a code sent to this address'),
        'emailed_code_local' => __('Local check only: a code was typed, but this is not genuine proof of the mailbox'),
        'genuine_sms' => __('Genuine SMS verification'),
        'simulated_local' => __('Simulated by the local email bridge. Not proof of the phone'),
        'mock_sms' => __('Mock SMS. No phone was reached'),
        'none' => __('Not used for this match'),
    ];
    $noticeNames = ['requested' => __('Request received'), 'alert' => __('Alert to old address'), 'approved' => __('Approval'), 'rejected' => __('Closed or rejected'), 'reset' => __('Password reset link')];
    $noticeLabels = ['queued' => __('Queued'), 'sent' => __('Sent'), 'failed' => __('Failed'), 'blocked' => __('Blocked'), 'not_queued' => __('Not queued')];
@endphp

<x-ui.page-head :title="$recovery->reference" :subtitle="__('Account recovery request')" />

<p><a href="{{ route('admin.recovery.index') }}" class="btn btn-sm btn-outline-secondary">{{ __('Back to requests') }}</a></p>

@if($recovery->isLocal())
    <div class="en-notice mb-3" role="status">{{ __('Local development request. The email and mobile checks were simulated, so this is not genuine proof of ownership.') }}</div>
@endif

<div class="row">
    <div class="col-lg-7">
        <x-ui.card :title="__('Request details')" class="mb-3">
            <dl class="row mb-0">
                <dt class="col-sm-4">{{ __('Status') }}</dt><dd class="col-sm-8">{{ $statusLabels[$recovery->status] }}</dd>
                <dt class="col-sm-4">{{ __('Approvable until') }}</dt><dd class="col-sm-8">{{ $recovery->expiresAt()?->ist('d M Y, h:i A') }} IST @if($recovery->isOpen() && $recovery->isExpired())<span class="en-badge en-badge-rejected">{{ __('Expired') }}</span>@endif</dd>
                <dt class="col-sm-4">{{ __('Applicant') }}</dt><dd class="col-sm-8">{{ $recovery->applicant_name }}</dd>
                <dt class="col-sm-4">{{ __('Verified email') }}</dt><dd class="col-sm-8">{{ $maskedEmail }}</dd>
                <dt class="col-sm-4">{{ __('Email proof') }}</dt><dd class="col-sm-8">{{ $proof[$recovery->email_proof] }}</dd>
                <dt class="col-sm-4">{{ __('Verified mobile') }}</dt><dd class="col-sm-8">{{ $recovery->verified_mobile ? \App\Services\SmsService::mask($recovery->verified_mobile) : '-' }}</dd>
                <dt class="col-sm-4">{{ __('Mobile proof') }}</dt><dd class="col-sm-8">{{ $proof[$recovery->mobile_proof] }}</dd>
                <dt class="col-sm-4">{{ __('Matched by') }}</dt><dd class="col-sm-8">{{ ['email' => __('Email'), 'mobile' => __('Mobile'), 'both' => __('Email and mobile')][$recovery->match_type] }}</dd>
                <dt class="col-sm-4">{{ __('Old account ID') }}</dt><dd class="col-sm-8">{{ $recovery->old_user_id }}</dd>
                <dt class="col-sm-4">{{ __('Old account role') }}</dt><dd class="col-sm-8">{{ $roleLabels[$recovery->deletion->role] ?? $recovery->deletion->role }}</dd>
                <dt class="col-sm-4">{{ __('Requested') }}</dt><dd class="col-sm-8">{{ $recovery->created_at?->ist('d M Y, h:i A') }}</dd>
                @if($recovery->reviewer)
                    <dt class="col-sm-4">{{ __('Decided by') }}</dt><dd class="col-sm-8">{{ $recovery->reviewer->name }}, {{ $recovery->reviewed_at?->ist('d M Y, h:i A') }}</dd>
                @endif
                @if($recovery->decision_reason)
                    <dt class="col-sm-4">{{ __('Reason') }}</dt><dd class="col-sm-8">{{ $recovery->decision_reason }}</dd>
                @endif
                @if($recovery->conflict_resolution)
                    <dt class="col-sm-4">{{ __('Conflict resolution') }}</dt><dd class="col-sm-8">{{ $recovery->conflict_resolution }}</dd>
                @endif
            </dl>
        </x-ui.card>

        <x-ui.card :title="__('Deletion history')" class="mb-3">
            <ul class="en-req-list mb-0">
                @foreach($history as $deletion)
                    <li><span>{{ $deletion->reference }}: {{ $deletion->reason }} <span class="en-meta">{{ __('by :name', ['name' => $deletion->deletedBy?->name]) }}</span></span><span class="en-meta text-nowrap">{{ $deletion->deleted_at->ist('d M Y') }}</span></li>
                @endforeach
            </ul>
        </x-ui.card>

        <x-ui.card :title="__('Email status')" class="mb-3">
            @foreach(\App\Models\AccountRecoveryRequest::NOTICES as $notice)
                @if($status = $recovery->noticeStatus($notice))
                    <div class="mb-1">
                        <span class="en-meta">{{ $noticeNames[$notice] }}:</span> {{ $noticeLabels[$status] }}
                        @if($recovery->canRetry($notice))
                            <form action="{{ route('admin.recovery.retry', [$recovery, $notice]) }}" method="POST" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-link p-0">{{ __('Retry') }}</button>
                            </form>
                        @endif
                    </div>
                @endif
            @endforeach
            @if($recovery->status === 'approved')
                @php($restored = $recovery->deletion->noticeStatus(\App\Models\AccountDeletion::NOTICE_RESTORE))
                <div class="mb-1">
                    <span class="en-meta">{{ __('Restoration') }}:</span> {{ $noticeLabels[$restored] }}
                    @if($recovery->deletion->canRetry(\App\Models\AccountDeletion::NOTICE_RESTORE))
                        <form action="{{ route('admin.users.deletion.retry', [$recovery->deletion, \App\Models\AccountDeletion::NOTICE_RESTORE]) }}" method="POST" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-link p-0">{{ __('Retry') }}</button>
                        </form>
                    @endif
                </div>
            @endif
            <p class="en-meta mt-2 mb-0">{{ __('Sent means the mail service accepted the email. It does not prove that it arrived.') }}</p>
        </x-ui.card>
    </div>

    <div class="col-lg-5">
        @if($assessment)
            @if($assessment['blocked'])
                <div class="alert alert-danger" role="alert"><strong>{{ __('Cannot be resolved here.') }}</strong> {{ $assessment['blocked'] }}</div>
            @elseif($assessment['needs_confirmation'])
                <div class="alert alert-danger" role="alert">
                    <strong>{{ __('Identity conflict.') }}</strong>
                    {{ __('Another active account holds this identity, or the new account already has records. Accounts are never merged. Approving archives these accounts and restores the original.') }}
                    <ul class="mb-0 mt-2">
                        @foreach($assessment['accounts'] as $account)
                            <li>#{{ $account->id }} {{ $account->name }} ({{ $roleLabels[$account->role] ?? $account->role }})</li>
                        @endforeach
                    </ul>
                </div>
            @else
                <div class="en-notice mb-3" role="status">{{ __('No conflict. The new blank account made for this request will be archived, and the original restored.') }}</div>
            @endif

            @if($assessment['stays_deactivated'])
                <div class="en-notice mb-3" role="status">{{ __('This account was deactivated before it was deleted, so it stays deactivated after recovery until you reactivate it.') }}</div>
            @endif

            @if($recovery->deletion->role === 'notary')
                <div class="en-notice mb-3" role="status">{{ __('Approving does not restore Notary access. The account stays pending until you approve it again under Notaries.') }}</div>
            @endif
        @endif

        @if($canDecide)
            <x-ui.card :title="__('Decision')" class="mb-3">
                @if($recovery->status === 'pending')
                    <form method="POST" action="{{ route('admin.recovery.review', $recovery) }}" class="mb-3">
                        @csrf
                        <button type="submit" class="btn btn-outline-secondary btn-sm">{{ __('Mark as Under Review') }}</button>
                    </form>
                @endif

                @unless($assessment['blocked'])
                    <form method="POST" action="{{ route('admin.recovery.approve', $recovery) }}" class="mb-4" data-confirm="{{ __('Approve this recovery? The original account is restored at once, its old password stops working, and a password reset link is emailed to its registered address.') }}">
                        @csrf
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="identity_checked" value="1" id="f-identity" required>
                            <label class="form-check-label" for="f-identity">{{ __('I have checked this person\'s identity.') }}</label>
                        </div>
                        @if($assessment['needs_confirmation'])
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="conflict_confirmed" value="1" id="f-conflict" required>
                                <label class="form-check-label" for="f-conflict">{{ __('I understand that the accounts listed will be archived, and that nothing is merged.') }}</label>
                            </div>
                            <label class="form-label" for="f-conflict-reason">{{ __('Reason for this resolution') }}</label>
                            <textarea id="f-conflict-reason" name="conflict_reason" class="form-control mb-2" rows="2" minlength="10" maxlength="500" required></textarea>
                        @endif
                        <button type="submit" class="btn btn-primary w-100">{{ __('Approve recovery') }}</button>
                    </form>
                @endunless

                <form method="POST" action="{{ route('admin.recovery.reject', $recovery) }}">
                    @csrf
                    <label class="form-label" for="f-reject">{{ __('Reason for rejection') }}</label>
                    <textarea id="f-reject" name="reason" class="form-control mb-2" rows="2" minlength="10" maxlength="500" required></textarea>
                    <div class="form-text mb-2">{{ __('This reason is emailed to the applicant.') }}</div>
                    <button type="submit" class="btn btn-outline-danger w-100">{{ __('Reject recovery') }}</button>
                    <button type="submit" formaction="{{ route('admin.recovery.cancel', $recovery) }}" class="btn btn-outline-secondary w-100 mt-2">{{ __('Cancel recovery, keep the new account') }}</button>
                </form>
            </x-ui.card>
        @endif
    </div>
</div>
@endsection
