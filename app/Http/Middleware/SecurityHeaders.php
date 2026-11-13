<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser-hardening headers for every web response. All assets are served from this
 * site. Inline scripts and styles are still allowed, as pages use them, so this policy
 * limits where content can load from but does not stop an inline script. A response
 * that sets its own policy, such as a sandboxed document preview, keeps it.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // A demonstration: no page, PDF or download should appear in search results.
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        // A page carrying a single-use token in its address sets the stricter no-referrer itself.
        if (! $response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        // The profile photo capture uses this site's own camera access.
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; media-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
        }

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
