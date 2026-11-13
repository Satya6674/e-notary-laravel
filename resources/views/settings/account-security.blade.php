{{-- A person's own verification state and recent sign-in activity. --}}
<div class="col-lg-7">
    <div class="card">
        <div class="card-header"><h2>{{ __('Account Security') }}</h2></div>
        <div class="card-body">
            <dl class="row mb-3">
                <dt class="col-sm-4">{{ __('Email') }}</dt>
                <dd class="col-sm-8">{{ \App\Services\DeliveryLog::maskEmail($user->email) }} <span class="en-badge {{ $user->hasVerifiedEmail() ? 'en-badge-approved' : 'en-badge-pending' }}">{{ __($user->hasVerifiedEmail() ? 'Verified' : 'Not verified') }}</span></dd>
                <dt class="col-sm-4">{{ __('Mobile') }}</dt>
                <dd class="col-sm-8">{{ $user->mobile ? \App\Services\SmsService::mask($user->mobile) : '-' }} <span class="en-badge {{ $user->hasVerifiedMobile() ? 'en-badge-approved' : ($user->mobileFallbackVerified() ? 'en-badge-assigned' : 'en-badge-pending') }}">{{ __($user->hasVerifiedMobile() ? 'Verified' : ($user->mobileSimulationAccepted() ? 'Development Verified' : ($user->mobileFallbackVerified() ? 'Verified via Email Fallback' : 'Not verified'))) }}</span></dd>
            </dl>
            <h3 class="h6">{{ __('Recent Sign-in Activity') }}</h3>
            <ul class="en-req-list mb-3">
                @forelse($activity as $entry)
                    <li><span>{{ __(['auth.login' => 'Signed in', 'auth.logout' => 'Signed out', 'auth.login_failed' => 'Failed sign-in', 'auth.password_reset' => 'Password reset', 'auth.mobile_verified' => 'Mobile verified', 'auth.email_verified' => 'Email verified'][$entry->action] ?? 'Account activity') }}</span><span class="en-meta text-nowrap">{{ $entry->created_at?->ist() }} IST</span></li>
                @empty
                    <li>{{ __('No activity recorded yet.') }}</li>
                @endforelse
            </ul>
            <a class="btn btn-sm btn-outline-primary" href="{{ route('privacy-center.index') }}">{{ __('Privacy Center') }}</a>
        </div>
    </div>
</div>
