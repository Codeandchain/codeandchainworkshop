<?php

namespace Modules\Atelier\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Atelier\App\Services\Interfaces\AtelierServiceInterface;

class AtelierController extends Controller
{
    /**
     * AtelierController constructor.
     *
     * @param AtelierServiceInterface $atelierService
     */
    public function __construct(protected AtelierServiceInterface $atelierService)
    {
    }

    /**
     * GET /api/info — le mode, l'URL du webhook à configurer, la protection.
     */
    public function info()
    {
        return response()->json($this->atelierService->info());
    }

    /**
     * GET /api/journal — l'activité, du plus récent au plus ancien.
     */
    public function journal()
    {
        return response()->json(['journal' => $this->atelierService->journal()]);
    }

    /**
     * GET /api/echanges?depuis=n — le fil, à partir du n° donné.
     */
    public function echanges(Request $request)
    {
        return response()->json(['echanges' => $this->atelierService->echanges((int) $request->query('depuis', 0))]);
    }

    /**
     * POST /api/demo/rejouer-webhook — renvoie le dernier webhook authentique.
     */
    public function rejouerWebhook()
    {
        $resultat = $this->atelierService->rejouerDernierWebhook();
        if ($resultat === null) {
            return response()->json(['erreur' => 'aucun webhook reçu pour l\'instant'], 409);
        }
        [$code, $reponse] = $resultat;

        return response()->json($reponse, $code);
    }
}
