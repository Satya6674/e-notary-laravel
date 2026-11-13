<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a person out of the portals while they must choose what to do about a previous
 * account, or while a recovery request is waiting for an administrator.
 */
class EnsureAccountChoice
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->user()?->accountChoiceRoute();

        if ($route) {
            if ($request->expectsJson()) {
                abort(403, 'Please finish the step about your previous account first.');
            }

            return redirect()->route($route);
        }

        return $next($request);
    }
}
