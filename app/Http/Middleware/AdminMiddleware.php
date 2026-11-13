<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allows a request to continue only if the logged-in user has the "admin" role.
 * Registered as the "admin" alias in bootstrap/app.php.
 */
class AdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Not logged in, or logged in with another role: show a 403 error page.
        if (!Auth::check() || Auth::user()->role !== 'admin') {
            abort(403, 'Admin access only');
        }

        return $next($request);
    }
}
