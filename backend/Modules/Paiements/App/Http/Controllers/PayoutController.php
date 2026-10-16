<?php

namespace Modules\Paiements\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\App\Helpers\Texte;
use Modules\Core\App\Services\Interfaces\TraceurInterface;
use Modules\Paiements\App\Exceptions\PayoutDesactiveException;
use Modules\Paiements\App\Services\Interfaces\PayoutServiceInterface;

class PayoutController extends Controller
{
    /**
     * PayoutController constructor.
     *
     * @param PayoutServiceInterface $payoutService
     * @param TraceurInterface $traceur
     */
    public function __construct(
        protected PayoutServiceInterface $payoutService,
        protected TraceurInterface $traceur,
    ) {
    }

    /**
     * POST /api/pay — payer une facture Lightning (le chemin sortant).
     */
    public function __invoke(Request $request)
    {
        $bolt11 = (string) $request->input('invoice', '');
        $this->traceur->echange(['cle' => 'payout.demande', 'de' => 'nav', 'vers' => 'serveur', 'type' => 'req',
            'http' => ['methode' => 'POST', 'url' => '/api/pay'], 'corps' => Texte::abreger(['invoice' => $bolt11])]);

        try {
            $resultat = $this->payoutService->payer($bolt11);
        } catch (PayoutDesactiveException $e) {
            return response()->json(['erreur' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            if (! $e instanceof \InvalidArgumentException) {
                report($e);
            }
            $this->traceur->noter('payout', 'Échec du payout', $e->getMessage(), false);
            $this->traceur->echange(['cle' => 'payout.reponse', 'de' => 'serveur', 'vers' => 'nav', 'type' => 'rep', 'ko' => true,
                'http' => ['methode' => 'POST', 'url' => '/api/pay', 'statut' => 400], 'corps' => ['erreur' => $e->getMessage()]]);

            return response()->json(['erreur' => $e->getMessage()], 400);
        }

        $this->traceur->echange(['cle' => 'payout.reponse', 'de' => 'serveur', 'vers' => 'nav', 'type' => 'rep',
            'http' => ['methode' => 'POST', 'url' => '/api/pay', 'statut' => 200], 'corps' => Texte::abreger($resultat)]);

        return response()->json($resultat);
    }
}
