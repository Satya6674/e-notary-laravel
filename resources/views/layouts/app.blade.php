<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    @include('partials.head')
    <title>@yield('title', 'E-Notary') · E-Notary</title>
</head>
<body>
    <a class="en-skip" href="#main">{{ __('Skip to content') }}</a>
    @include('partials.mail-mode-banner')

    @php($user = auth()->user())
    @php($notice = \App\Models\Setting::get('portal_notice'))
    @php($public = ! $user || View::hasSection('fullwidth'))

    @if($public)
        @include('partials.site-header')
    @endif

    <div class="en-shell">
        @unless($public)
            @include('partials.sidebar')
        @endunless

        <div class="en-main">
            @unless($public)
                <header class="en-topbar en-no-print">
                    <button type="button" class="en-icon-btn en-sidebar-toggle" aria-label="{{ __('Menu') }}" onclick="document.body.classList.toggle('en-nav-open')"><i class="fas fa-bars" aria-hidden="true"></i></button>

                    <div class="en-topbar-tools">
                        @include('partials.language-switcher')

                        @if(\App\Support\Platform::schemaReady())
                            @php($unread = $user->unreadNotifications()->count())
                            <a class="en-icon-btn" href="{{ route('notifications.index') }}" aria-label="{{ __('Notifications') }}">
                                <i class="fas fa-bell" aria-hidden="true"></i>
                                @if($unread)<span class="en-count">{{ $unread }}</span>@endif
                            </a>
                        @endif
                        <span class="en-user-name small fw-bold">{{ $user->name }}</span>
                        <form action="{{ route('logout') }}" method="POST">
                            @csrf
                            <button class="en-icon-btn" type="submit" aria-label="{{ __('Logout') }}" title="{{ __('Logout') }}"><i class="fas fa-right-from-bracket" aria-hidden="true"></i></button>
                        </form>
                    </div>
                </header>
            @endunless

            <main class="{{ View::hasSection('fullwidth') ? '' : 'en-content' }}" id="main" tabindex="-1">
                @if($notice)
                    <div class="alert alert-warning en-no-print" role="status">{{ $notice }}</div>
                @endif

                {{-- An Admin's time-limited login exception. It verifies nothing, so say so. --}}
                @if($user?->usingTemporaryAccess())
                    <div class="alert alert-warning en-no-print" role="status">
                        <strong>{{ __('Temporary Access Active') }}</strong> ·
                        {{ __('An administrator gave you limited access until :time. Some actions stay unavailable until you complete your verification.', ['time' => $user->activeTemporaryAccess()->expires_at->ist('d M Y H:i')]) }}
                        @if($verifyRoute = $user->nextVerificationRoute())
                            <a href="{{ route($verifyRoute) }}" class="alert-link">{{ __('Complete verification') }}</a>
                        @endif
                    </div>
                @endif

                {{-- Flash messages set by controllers, plus any validation errors. --}}
                @foreach(['status' => 'success', 'success' => 'success', 'error' => 'danger'] as $key => $tone)
                    {{-- The email page shows its own message for this internal status. --}}
                    @if(session($key) && session($key) !== 'verification-link-sent')
                        <div class="alert alert-{{ $tone }} alert-dismissible fade show en-no-print" role="{{ $tone === 'danger' ? 'alert' : 'status' }}">
                            {{ __(session($key)) }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('Close') }}"></button>
                        </div>
                    @endif
                @endforeach

                @if($errors->any())
                    <div class="alert alert-danger alert-dismissible fade show en-no-print" role="alert">
                        <strong>{{ __('There were some problems with your input.') }}</strong>
                        <ul class="mb-0 mt-2">
                            @foreach($errors->all() as $error)
                                <li>{{ __($error) }}</li>
                            @endforeach
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('Close') }}"></button>
                    </div>
                @endif

                {{-- Each page fills this section with its own content. --}}
                @yield('content')
            </main>

            @unless($public)
                <footer class="text-center py-3 en-no-print">
                    <span class="small"><a href="{{ route('privacy') }}">{{ __('Privacy Policy') }}</a> &middot; <a href="{{ route('terms') }}">{{ __('Terms of Use') }}</a></span>
                    <p class="en-legal-line">@include('partials.disclosure')</p>
                    {{-- Citizen Portal only, last line of the footer, once per page (owner decision 2026-11-13). --}}
                    @if($user->role === 'public')
                        <p class="en-prototype-line">{{ \App\Support\Platform::PROTOTYPE_NOTICE }}</p>
                    @endif
                </footer>
            @endunless
        </div>
    </div>

    @if($public)
        @include('partials.site-footer')
    @endif

    <script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
    @include('partials.confirm-modal')
    <script>
    // A plain form submission shows that it is working and cannot be sent twice. Forms that
    // handle their own submit (sign-in, sign-up, confirmation dialogs) prevent the default and
    // are left alone. The button is disabled only after the browser has read the form.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (form.hasAttribute('data-no-loading') || (form.method || '').toLowerCase() === 'get') { return; }
        var button = event.submitter || form.querySelector('[type=submit]');
        if (! button || button.dataset.busy) { return; }
        // Checked after every other listener has run: the confirmation dialog registers later
        // than this one and cancels the submission, and then nothing is sent yet.
        setTimeout(function () {
            if (event.defaultPrevented) { return; }
            button.dataset.busy = '1';
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.insertAdjacentHTML('afterbegin', '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>');
        }, 0);
    });
    // Coming back with the browser's Back button shows the page as it was left: re-enable it.
    window.addEventListener('pageshow', function (event) {
        if (! event.persisted) { return; }
        document.querySelectorAll('[data-busy]').forEach(function (button) {
            button.disabled = false;
            button.removeAttribute('aria-busy');
            delete button.dataset.busy;
            var spinner = button.querySelector('.spinner-border');
            if (spinner) { spinner.remove(); }
        });
    });
    </script>
    @stack('scripts')
</body>
</html>
