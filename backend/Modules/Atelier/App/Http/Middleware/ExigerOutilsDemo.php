<?php

namespace Modules\Atelier\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * DEMO_TOOLS=false coupe le fil des échanges et le rejeu de webhook.
 */
class ExigerOutilsDemo
{
    public function handle(Request $request, Closure $next)
    {
        if (config('atelier.outils_demo')) {
            return $next($request);
        }

        return response()->json(['erreur' => 'outils de démo désactivés (DEMO_TOOLS=false)'], 404);
    }
}
