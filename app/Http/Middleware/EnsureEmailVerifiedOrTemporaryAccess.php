<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Symfony\Component\HttpFoundation\Response;

/**
 * The "verified" alias: Laravel's verified-email rule, except while an Admin's temporary access
 * grant is in force. The grant marks nothing as verified; RestrictTemporaryAccess then limits the
 * account to its short list of routes, and every other rule (role, Notary approval) still applies.
 */
class EnsureEmailVerifiedOrTemporaryAccess extends EnsureEmailIsVerified
{
    public function handle($request, Closure $next, $redirectToRoute = null): Response
    {
        if ($request->user()?->usingTemporaryAccess()) {
            return $next($request);
        }

        return parent::handle($request, $next, $redirectToRoute);
    }
}
