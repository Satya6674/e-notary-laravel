<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared pages (appointments, support, notifications, settings) serve all three
 * roles, so each role's own entry rules are applied here: citizens need their
 * verification steps done, notaries also need admin approval, admins pass.
 */
class EnsurePortalAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // None while an Admin's temporary access grant is in force. Notary approval is still checked below.
        if ($route = $user?->verificationGateRoute()) {
            return redirect()->route($route);
        }

        if ($user?->isNotary() && ! $user->isApprovedNotary()) {
            // A page view goes to the waiting page. An action is still refused outright.
            if ($request->isMethodSafe() && ! $request->expectsJson()) {
                return redirect()->route('notary.pending');
            }

            abort(403, 'Your notary account is awaiting admin approval.');
        }

        return $next($request);
    }
}
