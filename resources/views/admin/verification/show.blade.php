@extends('layouts.app')

@section('title', __('User Verification Management'))

@section('content')
@php
    $roleLabels = ['public' => __('Citizen'), 'notary' => __('Notary')];
    $methodLabels = [
        'email_otp' => __('Email OTP'),
        'signed_link' => __('Email link'),
        \App\Models\User::METHOD_EMAIL_FALLBACK => __('Verified via Email Fallback'),
        \App\Models\User::METHOD_SMS => __('SMS OTP'),
        \App\Models\User::METHOD_MOCK_SMS => __('Mock SMS, no phone reached'),
        \App\Models\User::METHOD_DEV_EMAIL => __('Development email OTP'),
    ];
    $grantLabels = [
        \App\Models\TemporaryAccessGrant::STATUS_ACTIVE => ['Temporary Access Active', 'en-badge-correction'],
        \App\Models\TemporaryAccessGrant::STATUS_SCHEDULED => ['Temporary Access Active', 'en-badge-correction'],
        \App\Models\TemporaryAccessGrant::STATUS_EXPIRED => ['Temporary Access Expired', 'en-badge-rejected'],
        \App\Models\TemporaryAccessGrant::STATUS_REVOKED => ['Temporary Access Revoked', 'en-badge-rejected'],
    ];
    $evidence = \App\Services\ManualVerificationService::EVIDENCE_METHODS;
    $open = $supported && ! $refusal;
@endphp

<x-ui.page-head :title="__('Verification: :name', ['name' => $user->name])" :subtitle="__('Manual verification is recorded as an Admin check, never as an OTP or SMS. Nothing is sent to the account holder.')">
    <a href="{{ route('admin.verification.index') }}" class="btn btn-outline-primary"><i class="fas fa-arrow-left me-2" aria-hidden="true"></i>{{ __('Back') }}</a>
    <a href="{{ route('admin.users.show', $user) }}" class="btn btn-outline-secondary">{{ __('User Details') }}</a>
</x-ui.page-head>

@if($refusal)
    <div class="alert alert-warning" role="status">{{ __($refusal) }}</div>
@endif

<x-ui.card :title="__('Current status')">
    <dl class="row mb-0">
        <dt class="col-sm-3">{{ __('Role') }}</dt>
        <dd class="col-sm-9">
            {{ $roleLabels[$user->role] ?? $user->role }}
            @if($user->isNotary())
                @if($user->notary_approved_at)
                    <span class="en-badge en-badge-approved">{{ __('Approved') }}</span>
                @else
                    <span class="en-badge en-badge-pending">{{ __('Awaiting approval') }}</span>
                @endif
                <span class="en-meta d-block">{{ __('Notary approval is decided on the Notaries page. Nothing here changes it.') }}</span>
            @endif
        </dd>

        <dt class="col-sm-3">{{ __('Email') }}</dt>
        <dd class="col-sm-9">
            <span class="en-meta">{{ $maskedEmail }}</span>
            <span class="en-badge {{ $badges['email'][1] }}">{{ __($badges['email'][0]) }}</span>
        </dd>

        <dt class="col-sm-3">{{ __('Mobile') }}</dt>
        <dd class="col-sm-9">
            <span class="en-meta">{{ $maskedMobile ?? '-' }}</span>
            <span class="en-badge {{ $badges['mobile'][1] }}">{{ __($badges['mobile'][0]) }}</span>
            @if($user->mobileManualVerified())
                <span class="en-meta d-block">{{ __('Not SMS proof: SMS password reset stays unavailable for this number.') }}</span>
            @endif
        </dd>

        <dt class="col-sm-3">{{ __('Temporary access') }}</dt>
        <dd class="col-sm-9 mb-0">
            @if($badges['access'])
                <span class="en-badge {{ $badges['access'][1] }}">{{ __($badges['access'][0]) }}</span>
            @else
                <span class="en-meta">{{ __('Never granted') }}</span>
            @endif
        </dd>
    </dl>
</x-ui.card>

<div class="row g-3 mt-0">
    <div class="col-lg-6">
        <x-ui.card :title="__('Manual verification')">
            <p class="en-meta">{{ __('Use this only after you have independently confirmed that this person controls the address or number. Email and mobile are verified separately.') }}</p>
            <ul class="en-meta">
                <li>{{ __('Email') }}: {{ $emailRefusal ? __($emailRefusal) : __('Can be verified manually.') }}</li>
                <li>{{ __('Mobile') }}: {{ $mobileRefusal ? __($mobileRefusal) : __('Can be verified manually.') }}</li>
            </ul>

            @if($open && (! $emailRefusal || ! $mobileRefusal))
                <form method="POST" action="{{ route('admin.verification.verify', $user) }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="f-purpose">{{ __('What to verify') }} <span class="en-required" aria-hidden="true">*</span></label>
                        <select id="f-purpose" name="purpose" class="form-select @error('purpose') is-invalid @enderror" required>
                            <option value="">{{ __('Choose…') }}</option>
                            @unless($emailRefusal)<option value="email" @selected(old('purpose') === 'email')>{{ __('Email address') }} ({{ $maskedEmail }})</option>@endunless
                            @unless($mobileRefusal)<option value="mobile" @selected(old('purpose') === 'mobile')>{{ __('Mobile number') }} ({{ $maskedMobile }})</option>@endunless
                        </select>
                    </div>
                    @include('admin.verification.partials.evidence', ['prefix' => 'v'])
                    <div class="mb-3">
                        <label class="form-label" for="v-reason">{{ __('Reason') }} <span class="en-required" aria-hidden="true">*</span></label>
                        <textarea id="v-reason" name="reason" class="form-control @error('reason') is-invalid @enderror" rows="3" minlength="20" maxlength="500" required>{{ old('reason') }}</textarea>
                        <div class="form-text">{{ __('At least 20 characters. Do not write passwords, codes or full contact details here.') }}</div>
                    </div>
                    @include('admin.verification.partials.confirm', ['prefix' => 'v', 'statement' => __('I confirm I independently verified that this person controls the selected email address or mobile number. This will be recorded as Verified Manually by Admin, not as an OTP or SMS.')])
                    <button type="submit" class="btn btn-primary">{{ __('Verify manually') }}</button>
                </form>
            @endif
        </x-ui.card>
    </div>

    <div class="col-lg-6">
        <x-ui.card :title="__('Temporary login access')">
            <p class="en-meta">{{ __('A time-limited exception for a person who cannot finish verification. It marks nothing as verified and does not approve a Notary. It opens only pages that display information, Support, the Privacy Center and the verification pages; applications, uploads, appointments, account changes and Notary decisions stay closed. It ends automatically at its expiry.') }}</p>

            @if($open && ! $grantRefusal)
                <form method="POST" action="{{ route('admin.verification.access.grant', $user) }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="g-hours">{{ __('Duration in hours') }} <span class="en-required" aria-hidden="true">*</span></label>
                        <input id="g-hours" name="hours" type="number" class="form-control @error('hours') is-invalid @enderror" min="{{ \App\Models\TemporaryAccessGrant::MIN_HOURS }}" max="{{ \App\Models\TemporaryAccessGrant::MAX_HOURS }}" value="{{ old('hours', \App\Models\TemporaryAccessGrant::DEFAULT_HOURS) }}" required>
                        <div class="form-text">{{ __('Between 1 and 72 hours.') }}</div>
                    </div>
                    @include('admin.verification.partials.evidence', ['prefix' => 'g'])
                    <div class="mb-3">
                        <label class="form-label" for="g-reason">{{ __('Reason') }} <span class="en-required" aria-hidden="true">*</span></label>
                        <textarea id="g-reason" name="reason" class="form-control @error('reason') is-invalid @enderror" rows="3" minlength="20" maxlength="500" required>{{ old('reason') }}</textarea>
                    </div>
                    @include('admin.verification.partials.confirm', ['prefix' => 'g', 'statement' => __('I confirm this exception is needed, and I understand it does not verify the email address or mobile number.')])
                    <button type="submit" class="btn btn-primary">{{ __('Grant temporary access') }}</button>
                </form>
            @elseif($grantRefusal && ! $refusal)
                <p class="mb-0">{{ __($grantRefusal) }}</p>
            @endif
        </x-ui.card>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header"><h2>{{ __('Temporary access history') }}</h2></div>
    @if($grants->isEmpty())
        <div class="card-body"><p class="en-meta mb-0">{{ __('No temporary access has been granted to this account.') }}</p></div>
    @else
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>{{ __('Granted') }}</th><th>{{ __('By') }}</th><th>{{ __('Expires') }}</th><th>{{ __('Status') }}</th><th>{{ __('Evidence') }}</th><th>{{ __('Reason') }}</th><th>{{ __('Revoked') }}</th></tr></thead>
                <tbody>
                    @foreach($grants as $grant)
                        @php([$label, $class] = $grantLabels[$grant->status()])
                        <tr>
                            <td class="text-nowrap">{{ $grant->created_at?->ist('d M Y H:i') }}</td>
                            <td>{{ $grant->grantor?->name ?? '-' }}</td>
                            <td class="text-nowrap">{{ $grant->expires_at?->ist('d M Y H:i') }}</td>
                            <td><span class="en-badge {{ $class }}">{{ __($label) }}</span></td>
                            <td>{{ __($evidence[$grant->evidence_method] ?? $grant->evidence_method) }}</td>
                            <td>{{ $grant->reason }}</td>
                            <td>
                                @if($grant->revoked_at)
                                    {{ $grant->revoked_at->ist('d M Y H:i') }} · {{ $grant->revoker?->name ?? '-' }}
                                    <span class="en-meta d-block">{{ $grant->revoke_reason }}</span>
                                @elseif(in_array($grant->status(), [\App\Models\TemporaryAccessGrant::STATUS_ACTIVE, \App\Models\TemporaryAccessGrant::STATUS_SCHEDULED], true))
                                    <form method="POST" action="{{ route('admin.verification.access.revoke', [$user, $grant]) }}">
                                        @csrf
                                        <div class="mb-2">
                                            <label class="form-label" for="r-reason-{{ $grant->id }}">{{ __('Reason for revoking') }} <span class="en-required" aria-hidden="true">*</span></label>
                                            <input id="r-reason-{{ $grant->id }}" name="revoke_reason" class="form-control form-control-sm" minlength="10" maxlength="500" required>
                                        </div>
                                        @include('admin.verification.partials.confirm', ['prefix' => 'r'.$grant->id, 'statement' => __('I confirm this temporary access should end now.')])
                                        <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Revoke') }}</button>
                                    </form>
                                @else
                                    <span class="en-meta">-</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

{{-- Read-only and append-only. Masked contacts only; no hash, code or password is ever read. --}}
<div class="card mt-3">
    <div class="card-header"><h2>{{ __('Verification history') }}</h2></div>
    @if(! $historySupported)
        <div class="card-body"><p class="en-meta mb-0">{{ __('Verification history appears here once the database update for it has been applied.') }}</p></div>
    @elseif($history->isEmpty())
        <div class="card-body"><p class="en-meta mb-0">{{ __('No verification has been recorded for this account yet.') }}</p></div>
    @else
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Purpose') }}</th><th>{{ __('Method') }}</th><th>{{ __('Address or number') }}</th><th>{{ __('By') }}</th><th>{{ __('Evidence') }}</th><th>{{ __('Status change') }}</th><th>{{ __('Reason') }}</th></tr></thead>
                <tbody>
                    @foreach($history as $event)
                        <tr>
                            <td class="text-nowrap">{{ \Illuminate\Support\Carbon::parse($event->verified_at ?? $event->created_at)->ist('d M Y H:i') }}</td>
                            <td>{{ $event->purpose === 'email' ? __('Email') : __('Mobile') }}</td>
                            <td>
                                @if($event->method === \App\Models\User::METHOD_ADMIN_MANUAL)
                                    <span class="en-badge en-badge-assigned">{{ __('Verified Manually by Admin') }}</span>
                                @else
                                    {{ $methodLabels[$event->method] ?? $event->method }}
                                @endif
                            </td>
                            <td>{{ $event->target_masked }}</td>
                            <td>{{ $event->actor_name ?? __('Account holder') }}</td>
                            <td>{{ isset($event->evidence_method) ? __($evidence[$event->evidence_method] ?? $event->evidence_method) : '-' }}</td>
                            <td class="text-nowrap">{{ isset($event->previous_status) ? $event->previous_status.' → '.$event->new_status : '-' }}</td>
                            <td>{{ $event->reason ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
