<?php

namespace Modules\Paiements\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Paiements\App\Services\Interfaces\FactureServiceInterface;

/**
 * PHP ne garde rien en mémoire entre deux requêtes (pas de setTimeout).
 * Avant chaque requête API, on fait donc avancer le temps : factures
 * expirées et, en simulation, paiements dont le délai est passé.
 */
class AvancerLeTemps
{
    public function __construct(protected FactureServiceInterface $factureService)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $this->factureService->avancerLeTemps();

        return $next($request);
    }
}
