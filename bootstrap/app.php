<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureAdminMfa;
use App\Http\Middleware\RequireAdminReauth;
use App\Http\Middleware\EnsureAccountChoice;
use App\Http\Middleware\EnsureEmailVerifiedOrBridge;
use App\Http\Middleware\EnsureEmailVerifiedOrTemporaryAccess;
use App\Http\Middleware\EnsureMobileIsVerified;
use App\Http\Middleware\EnsurePortalAccess;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureSchemaReady;
use App\Http\Middleware\NotaryMiddleware;
use App\Http\Middleware\RestrictTemporaryAccess;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

// Stack traces must not carry argument values: XAMPP's php.ini has this Off, and a trace written
// to storage/logs or failed_jobs would then show an OTP, a recovery code or a token passed to a
// function. Set here so the web server, the console and the queue all get it.
ini_set('zend.exception_ignore_args', '1');

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Short names used in routes/web.php, e.g. middleware(['auth', 'admin']).
        $middleware->alias([
            'admin'  => AdminMiddleware::class,
            'notary' => NotaryMiddleware::class,
            'role'   => EnsureRole::class,
            // Citizen area: SMS OTP for the mobile number is the second sign-up step.
            'mobile.verified' => EnsureMobileIsVerified::class,
            'verified.or.bridge' => EnsureEmailVerifiedOrBridge::class,
            // Laravel's verified-email rule, which an Admin's temporary access grant can pass.
            'verified' => EnsureEmailVerifiedOrTemporaryAccess::class,
            'schema.ready' => EnsureSchemaReady::class,
            'portal' => EnsurePortalAccess::class,
            'account.choice' => EnsureAccountChoice::class,
            // Sensitive Admin actions: the Admin's password confirmed within the step-up window.
            'admin.reauth' => RequireAdminReauth::class,
        ]);

        // Browser-hardening headers and language choice on every web response.
        // AuthenticateSession ends a user's other sessions after a password change.
        // EnsureAdminMfa holds an Admin with two-factor sign-in at the challenge until it is passed.
        $middleware->web(append: [AuthenticateSession::class, EnsureAccountActive::class, EnsureAdminMfa::class, RestrictTemporaryAccess::class, SecurityHeaders::class, SetLocale::class]);

        // Reverse proxies (H-2). WHICH proxies are trusted is config('trustedproxy.proxies')
        // (TRUSTED_PROXIES), read by Laravel's TrustProxies middleware on each request. Never read
        // it here with env(): this callback runs before .env is loaded, so the value would be lost.
        // WHAT a trusted proxy may tell us is limited to the client address and the scheme
        // (X-Forwarded-For, X-Forwarded-Proto). X-Forwarded-Port, -Host and -Prefix are NOT believed
        // even from a trusted proxy: proxies often pass them through unchanged from the client, and
        // they would shape the host, port and links this application generates (a forged
        // X-Forwarded-Port: 8443 turned links into https://host:8443/...). Without them the port comes
        // from the Host header, or is 443 for a trusted https. RFC 7239 "Forwarded" is not believed.
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);

        $middleware->redirectGuestsTo(fn () => route('login'));

        // Outside APP_ENV=local (Laravel skips the check there and in tests), only requests for the
        // APP_URL host are served, so a forged Host header cannot shape a generated link. Email links
        // are built on APP_URL in every environment anyway (App\Support\AppUrl).
        $middleware->trustHosts(at: fn () => array_filter([
            ($host = parse_url((string) config('app.url'), PHP_URL_HOST)) ? '^'.preg_quote($host).'$' : null,
        ]), subdomains: false);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // After a validation error the input is copied into the session (storage/framework/sessions)
        // so the form can be refilled. Codes, reset tokens and passwords must never be stored there.
        $exceptions->dontFlash(\App\Support\SensitiveInput::FIELDS);
    })
    ->create();
