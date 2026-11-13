<?php

namespace App\Http\Middleware;

use App\Support\Platform;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Portals use the 2026_11_01 tables, so they show a notice until those migrations run. */
class EnsureSchemaReady
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Platform::schemaReady()) {
            return response()->view('errors.schema-pending', [], 503);
        }

        return $next($request);
    }
}
