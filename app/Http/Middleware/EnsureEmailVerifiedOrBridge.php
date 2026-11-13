<?php

namespace App\Http\Middleware;

use App\Services\MobileOtpEmailBridge;
use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The normal verified-email rule, for the mobile step only. In local development that page
 * is where the single emailed code confirms the address, so it must open first.
 */
class EnsureEmailVerifiedOrBridge extends EnsureEmailIsVerified
{
    public function handle($request, Closure $next, $redirectToRoute = null): Response
    {
        return MobileOtpEmailBridge::enabled() ? $next($request) : parent::handle($request, $next, $redirectToRoute);
    }
}
