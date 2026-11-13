<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Citizen accounts must complete the SMS OTP step for their mobile number
 * before using the citizen area (second half of the sign-up verification,
 * after the email link). Registered as the "mobile.verified" alias.
 *
 * Enforced only once users.mobile_verified_at exists (migration
 * 2026_10_29_000001): until then User::needsMobileVerification() is false
 * for everyone, so the live app keeps working before the migration runs.
 */
class EnsureMobileIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // An Admin's temporary access grant passes this step without marking anything verified;
        // RestrictTemporaryAccess limits what the account can do meanwhile.
        if ($user && $user->needsMobileVerification() && ! $user->usingTemporaryAccess()) {
            if ($request->expectsJson()) {
                abort(403, 'Your mobile number must be verified first.');
            }

            return redirect()->route('verification.mobile.notice');
        }

        return $next($request);
    }
}
