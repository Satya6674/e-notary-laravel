<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allows a request to continue only if the logged-in user has the "notary"
 * role AND an admin has approved the notary account (users.notary_approved_at).
 * Registered as the "notary" alias in bootstrap/app.php.
 */
class NotaryMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user || $user->role !== 'notary') {
            abort(403, 'Notary access only');
        }

        if (! $user->isApprovedNotary()) {
            // A page view goes to the waiting page. An action is still refused outright.
            if ($request->isMethodSafe() && ! $request->expectsJson()) {
                return redirect()->route('notary.pending');
            }

            abort(403, 'Your notary account is awaiting admin approval.');
        }

        return $next($request);
    }
}
