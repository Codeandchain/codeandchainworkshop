<?php

namespace Modules\Core\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Si ADMIN_TOKEN est défini, la route exige l'en-tête x-admin-token.
 * Sans ADMIN_TOKEN (atelier en simulation), tout reste ouvert.
 */
class ExigerCodeAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $attendu = (string) config('core.admin_token');
        if ($attendu === '' || hash_equals($attendu, (string) $request->header('x-admin-token', ''))) {
            return $next($request);
        }

        return response()->json(['erreur' => 'code admin requis ou invalide', 'admin' => true], 401);
    }
}
