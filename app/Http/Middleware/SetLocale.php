<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Support\Platform;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Language order: this session's choice, the account's saved choice, the site default. */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $saved = Platform::schemaReady() ? $request->user()?->locale : null;
        $locale = $request->session()->get('locale') ?? $saved ?? Setting::get('default_locale');

        app()->setLocale(array_key_exists($locale, Platform::LOCALES) ? $locale : 'en');

        return $next($request);
    }
}
