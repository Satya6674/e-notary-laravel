<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generic role gate: `role:public`, `role:notary`, `role:admin` (or several,
 * comma separated). Registered as the "role" alias in bootstrap/app.php.
 *
 * Used on the citizen (public user) area so that admin and notary accounts
 * cannot act as citizens; the admin and notary areas keep their dedicated
 * middleware (AdminMiddleware / NotaryMiddleware) unchanged.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, $roles, true)) {
            abort(403, 'This area is only available to '.implode(' or ', $roles).' accounts.');
        }

        return $next($request);
    }
}
