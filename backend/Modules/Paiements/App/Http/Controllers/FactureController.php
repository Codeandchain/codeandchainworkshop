<?php

namespace Modules\Paiements\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\App\Helpers\Texte;
use Modules\Core\App\Services\Interfaces\TraceurInterface;
use Modules\Paiements\App\Services\Interfaces\FactureServiceInterface;

class FactureController extends Controller
{
    /**
     * FactureController constructor.
     *
     * @param FactureServiceInterface $factureService
     * @param TraceurInterface $traceur
     */
    public function __construct(
        protected FactureServiceInterface $factureService,
        protected TraceurInterface $traceur,
    ) {
    }

    /**
     * POST /api/invoices — créer une facture Lightning.
     */
    public function store(Request $request)
    {
        $this->traceur->echange(['cle' => 'facture.demande', 'de' => 'nav', 'vers' => 'serveur', 'type' => 'req',
            'http' => ['methode' => 'POST', 'url' => '/api/invoices'], 'corps' => Texte::abreger($request->all() ?: (object) [])]);

        $montant = $request->input('amount');
        if (! is_numeric($montant) || $montant < 1 || $montant > 20_000_000) {
            return response()->json(['erreur' => 'montant invalide (1 à 20 000 000 sats)'], 400);
        }
        $description = mb_substr((string) ($request->input('description') ?: 'Démo Bitnob'), 0, 120);

        try {
            $facture = $this->factureService->creer((int) round($montant), $description);
        } catch (\Throwable $e) {
            report($e);
            $this->traceur->noter('facture', 'Échec création facture', $e->getMessage(), false);
            $this->traceur->echange(['cle' => 'facture.reponse', 'de' => 'serveur', 'vers' => 'nav', 'type' => 'rep', 'ko' => true,
                'http' => ['methode' => 'POST', 'url' => '/api/invoices', 'statut' => 500], 'corps' => ['erreur' => $e->getMessage()]]);

            return response()->json(['erreur' => $e->getMessage()], 500);
        }

        $this->traceur->echange(['cle' => 'facture.reponse', 'de' => 'serveur', 'vers' => 'nav', 'type' => 'rep', 'bolt11' => $facture->payment_request,
            'http' => ['methode' => 'POST', 'url' => '/api/invoices', 'statut' => 201],
            'corps' => Texte::abreger(['id' => $facture->id, 'status' => $facture->status, 'montantSats' => $facture->montant_sats, 'paymentRequest' => $facture->payment_request])]);

        return response()->json($facture->pourNavigateur(), 201);
    }

    /**
     * GET /api/invoices/{id} — statut (polling de l'interface).
     * Jamais le navigateur ne déclare un paiement : il demande au serveur.
     */
    public function show(string $id)
    {
        try {
            $facture = $this->factureService->consulter($id);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['erreur' => $e->getMessage()], 500);
        }

        if (! $facture) {
            return response()->json(['erreur' => 'facture inconnue'], 404);
        }

        return response()->json($facture->statutPourNavigateur());
    }
}
