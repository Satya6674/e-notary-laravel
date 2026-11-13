@php
    $role = $user->role;
    // A notary awaiting approval has no working pages yet, so no links are offered.
    $pending = $user->isNotary() && ! $user->isApprovedNotary();
    $groups = $pending || $user->verificationGateRoute() || $user->accountChoiceRoute() ? [] : match ($role) {
        'admin' => [
            'Overview' => [
                ['admin.dashboard', 'fa-gauge-high', 'Dashboard', 'admin.dashboard'],
                ['admin.analytics.index', 'fa-chart-line', 'Analytics', 'admin.analytics.*'],
                ['reports.index', 'fa-chart-column', 'Reports', 'reports.*'],
            ],
            'Applications' => [
                ['admin.requests.index', 'fa-file-signature', 'Applications', 'admin.requests.*'],
                ['admin.documents.index', 'fa-folder-open', 'Documents', 'admin.documents.*'],
                ['appointments.index', 'fa-calendar-check', 'Appointments', 'appointments.*'],
            ],
            'People' => [
                ['admin.users.index', 'fa-users', 'Users', 'admin.users.*'],
                ['admin.notaries.index', 'fa-user-tie', 'Notaries', 'admin.notaries.*'],
                ...(\App\Models\User::accountRecoverySupported() ? [['admin.recovery.index', 'fa-user-shield', 'Account Recovery Requests', 'admin.recovery.*']] : []),
                ['admin.verification.index', 'fa-user-check', 'User Verification Management', 'admin.verification.*'],
            ],
            'Support' => [
                ['support.index', 'fa-life-ring', 'Support Tickets', 'support.*'],
                ['admin.messages.index', 'fa-envelope', 'Contact Messages', 'admin.messages.*'],
            ],
            'System' => [
                ['admin.security.index', 'fa-shield-halved', 'Security', 'admin.security.*'],
                ['admin.audit-logs.index', 'fa-clipboard-list', 'Audit Logs', 'admin.audit-logs.*'],
                ['admin.services.index', 'fa-list-check', 'Services', 'admin.services.*'],
                ['admin.delivery.index', 'fa-paper-plane', 'Email Delivery', 'admin.delivery.*'],
                ['settings.edit', 'fa-gear', 'Settings', 'settings.*'],
            ],
            'Account' => [
                ['admin.mfa.show', 'fa-key', 'Security & Two-Factor Authentication', 'admin.mfa.*'],
            ],
        ],
        'notary' => [
            'Overview' => [
                ['notary.dashboard', 'fa-gauge-high', 'Dashboard', 'notary.dashboard'],
                ['reports.index', 'fa-chart-column', 'Reports', 'reports.*'],
            ],
            'Work' => [
                ['notary.requests', 'fa-file-signature', 'Assigned Applications', 'notary.request*'],
                ['appointments.index', 'fa-calendar-check', 'Appointments', 'appointments.*'],
            ],
            'Account' => [
                ['notary.profile', 'fa-id-badge', 'My Profile', 'notary.profile'],
                ['support.index', 'fa-life-ring', 'Support Tickets', 'support.*'],
                ['privacy-center.index', 'fa-user-shield', 'Privacy Center', 'privacy-center.*'],
                ['settings.edit', 'fa-gear', 'Settings', 'settings.*'],
            ],
        ],
        default => [
            'Overview' => [
                ['dashboard', 'fa-gauge-high', 'Dashboard', 'dashboard'],
            ],
            'Applications' => [
                ['user.services.index', 'fa-file-circle-plus', 'Services', 'user.services.*'],
                ['user.requests', 'fa-file-signature', 'My Applications', 'user.requests*'],
                ['user.documents', 'fa-folder-open', 'My Documents', 'user.documents*'],
                ['appointments.index', 'fa-calendar-check', 'Appointments', 'appointments.*'],
            ],
            'Account' => [
                ['user.profile', 'fa-id-badge', 'My Profile', 'user.profile*'],
                ['support.index', 'fa-life-ring', 'Support Tickets', 'support.*'],
                ['privacy-center.index', 'fa-user-shield', 'Privacy Center', 'privacy-center.*'],
                ['settings.edit', 'fa-gear', 'Settings', 'settings.*'],
            ],
        ],
    };

    // Temporary access: offer only the pages it opens (RestrictTemporaryAccess refuses the rest anyway).
    if ($groups && $user->usingTemporaryAccess()) {
        $groups = array_filter(array_map(
            fn ($links) => array_values(array_filter($links, fn ($link) => \App\Http\Middleware\RestrictTemporaryAccess::allows($link[0]))),
            $groups
        ));
    }
@endphp

<aside class="en-sidebar en-no-print" aria-label="{{ __('Main navigation') }}">
    <a class="en-brand" href="{{ route('home') }}">
        <span class="en-brand-mark" aria-hidden="true"><i class="fas fa-gavel"></i></span>
        <span>
            <span class="en-brand-name d-block">E-Notary</span>
            <span class="en-brand-tag">{{ __(['admin' => 'Admin Portal', 'notary' => 'Notary Portal'][$role] ?? 'Citizen Portal') }}</span>
        </span>
    </a>

    <nav class="en-nav">
        @if($pending)
            <p class="en-nav-note">{{ __('Your notary account is waiting for administrator approval. Your portal will open here once it is approved.') }}</p>
        @elseif(! $groups)
            <p class="en-nav-note">{{ __('Please finish verifying your account to open your portal.') }}</p>
        @endif

        @foreach($groups as $label => $links)
            <div class="en-nav-label">{{ __($label) }}</div>
            @foreach($links as [$route, $icon, $text, $pattern])
                <a href="{{ route($route) }}" class="{{ request()->routeIs($pattern) ? 'active' : '' }}" @if(request()->routeIs($pattern)) aria-current="page" @endif>
                    <i class="fas {{ $icon }}" aria-hidden="true"></i> {{ __($text) }}
                </a>
            @endforeach
        @endforeach
    </nav>
</aside>
