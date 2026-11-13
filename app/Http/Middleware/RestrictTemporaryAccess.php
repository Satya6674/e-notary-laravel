<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While an account is past its pending verification only because of an Admin's temporary access
 * grant, it may use the signed-in routes on this list and nothing else. Anything that submits an
 * application, uploads, books, downloads a data export, changes the account (profile, email, mobile,
 * password) or makes a Notary decision is refused. Public pages are not affected.
 *
 * Runs on every web request, and the grant's expiry is compared with the clock each time, so access
 * ends the moment the grant expires or is revoked. Once every verification step is done the grant
 * changes nothing and this middleware lets everything through.
 */
class RestrictTemporaryAccess
{
    /** Route names open during temporary access. Deny by default: a new route is closed until listed. */
    public const ALLOWED_ROUTES = [
        'logout',
        'locale.update',
        // Finishing verification properly stays possible, with all its normal limits.
        'verification.notice', 'verification.send', 'verification.code', 'verification.verify',
        'verification.mobile.notice', 'mobile.verification.send', 'mobile.verification.verify',
        // Citizen pages that only display.
        'dashboard', 'user.requests', 'user.requests.show', 'user.documents', 'user.documents.show', 'user.services.index', 'user.profile',
        // Notary pages that only display. Approval is still checked by the notary middleware.
        'notary.pending', 'notary.dashboard', 'notary.requests', 'notary.request.show', 'notary.profile',
        'appointments.index',
        // Asking for help.
        'support.index', 'support.store', 'support.show', 'support.reply', 'support.close', 'support.attachment',
        'privacy-center.index', 'privacy-center.store', 'privacy-center.consent',
        'notifications.index', 'notifications.read-all', 'notifications.read',
        'settings.edit',
        'profile.photo.show',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $route = $request->route();

        if (! $user || ! $route || ! in_array('auth', $route->gatherMiddleware(), true) || ! $user->usingTemporaryAccess()) {
            return $next($request);
        }

        if (in_array($route->getName(), self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        $message = 'This is not available with temporary access. Please complete your verification first.';

        if ($request->isMethodSafe() && ! $request->expectsJson()) {
            return redirect()->to($user->landingUrl())->with('error', $message);
        }

        abort(403, $message);
    }

    public static function allows(?string $routeName): bool
    {
        return in_array($routeName, self::ALLOWED_ROUTES, true);
    }
}
