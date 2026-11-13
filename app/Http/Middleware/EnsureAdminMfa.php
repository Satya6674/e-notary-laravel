<?php

namespace App\Http\Middleware;

use App\Services\AdminMfa;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Admin two-factor gate, on every signed-in web request.
 * - An Admin with two-factor sign-in on who has not passed it in this session can only reach the
 *   challenge, sign out and the language switch.
 * - When ADMIN_MFA_REQUIRED is on, an Admin without it can only reach the setup page (and the
 *   password confirmation it needs), sign out and the language switch.
 * - FAIL CLOSED: when the two-factor state cannot be established (its table is missing while it is
 *   required, or looking up the Admin's factor fails or throws), every signed-in Admin page is
 *   refused with 503, except signing out and the language switch. Nothing is ever let through
 *   because a check could not run.
 * Public pages and other roles are not affected. While two-factor sign-in is optional and its table
 * has not been created yet, the feature is simply off (nobody can have enrolled).
 */
class EnsureAdminMfa
{
    /** Never depend on two-factor state: an Admin can always leave or switch language. */
    private const EXIT = ['logout', 'locale.update'];

    private const CHALLENGE = ['admin.mfa.challenge', 'admin.mfa.challenge.verify'];

    private const SETUP = ['admin.mfa.show', 'admin.mfa.enroll', 'admin.mfa.confirm', 'admin.reauth.show', 'admin.reauth.confirm'];

    public function __construct(private AdminMfa $mfa)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $route = $request->route();

        if (! $user || ! $route || ! $user->isAdmin() || ! in_array('auth', $route->gatherMiddleware(), true)) {
            return $next($request);
        }

        $name = $route->getName();

        if (in_array($name, self::EXIT, true)) {
            return $next($request);
        }

        try {
            if (! AdminMfa::available()) {
                // Required but its table is missing (not migrated, rolled back, wrong database):
                // two-factor sign-in cannot be enforced, so Admin pages close instead of opening.
                return AdminMfa::required() ? $this->unavailable($request, 'table_missing') : $next($request);
            }

            $enrolled = $this->mfa->enrolled($user);
        } catch (Throwable $e) {
            return $this->unavailable($request, 'lookup_failed', $e);
        }

        if (in_array($name, self::CHALLENGE, true)) {
            return $next($request);
        }

        if ($enrolled) {
            if (AdminMfa::passed($request, $user)) {
                return $next($request);
            }

            return $this->deny($request, 'admin.mfa.challenge', 'Enter the code from your authenticator app to continue.');
        }

        if (AdminMfa::required() && ! in_array($name, self::SETUP, true)) {
            return $this->deny($request, 'admin.mfa.show', 'Two-factor sign-in is required for Admin accounts. Set it up to continue.');
        }

        return $next($request);
    }

    private function deny(Request $request, string $route, string $message): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'redirect' => route($route)], 423);
        }

        return redirect()->route($route)->with('status', $message);
    }

    /**
     * Admin pages refused because two-factor state cannot be checked. A standalone page (no layout,
     * no database reads), so it still renders when the database is the problem. Logged for the
     * operator with the reason and the exception class only: no account details, no message text.
     */
    private function unavailable(Request $request, string $reason, ?Throwable $e = null): Response
    {
        Log::critical('Admin two-factor sign-in cannot be checked: Admin pages refused (fail closed).', array_filter([
            'reason' => $reason,
            'exception' => $e ? $e::class : null,
            'route' => $request->route()?->getName(),
        ]));

        // The language middleware runs later and reads the database, so take the choice from the
        // session only: this page must not depend on the database.
        $locale = $request->hasSession() ? $request->session()->get('locale') : null;
        app()->setLocale(is_string($locale) && array_key_exists($locale, \App\Support\Platform::LOCALES) ? $locale : 'en');

        $headers = ['Cache-Control' => 'no-store, private', 'Retry-After' => '300'];

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Admin pages are closed because two-factor sign-in cannot be checked right now.'], 503, $headers);
        }

        return response()->view('errors.admin-mfa-unavailable', [], 503, $headers);
    }
}
