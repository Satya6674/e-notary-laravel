@php($status = \App\Support\VerificationStatus::for($user))
@php($badge = fn (string $value) => match (true) {
    in_array($value, ['Verified', 'Verified (Email OTP)', 'Mobile Verified', 'Production verified'], true) => 'en-badge-approved',
    in_array($value, ['Verified via Email Fallback', 'Verified with email fallback'], true) => 'en-badge-assigned',
    default => 'en-badge-pending',
})
<dl class="row mb-3">
    <dt class="col-5">{{ __('Email') }}</dt>
    <dd class="col-7"><span class="en-meta d-block">{{ \App\Services\DeliveryLog::maskEmail($user->email) }}</span><span class="en-badge {{ $badge($status['email']) }}">{{ __($status['email']) }}</span></dd>

    <dt class="col-5">{{ __('Mobile') }}</dt>
    <dd class="col-7"><span class="en-meta d-block">{{ $user->mobile ? \App\Services\SmsService::mask($user->mobile) : __('N/A') }}</span><span class="en-badge {{ $badge($status['mobile']) }}">{{ __($status['mobile']) }}</span></dd>

    <dt class="col-5">{{ __('SMS') }}</dt>
    <dd class="col-7"><span class="en-badge {{ $user->hasVerifiedMobile() && $user->mobile_verification_method !== \App\Models\User::METHOD_MOCK_SMS ? 'en-badge-approved' : 'en-badge-pending' }}">{{ __(\App\Support\VerificationStatus::sms($user)) }}</span></dd>

    <dt class="col-5">{{ __('Account') }}</dt>
    <dd class="col-7"><span class="en-badge {{ $badge($status['account']) }}">{{ __($status['account']) }}</span></dd>

    <dt class="col-5">{{ __('Verification method') }}</dt>
    <dd class="col-7 mb-0">{{ __($status['method']) }}</dd>
</dl>
