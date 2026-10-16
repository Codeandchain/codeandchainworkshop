<?php

namespace Modules\Paiements\App\Services\Interfaces;

interface PayoutServiceInterface
{
    /**
     * Paie une facture BOLT11 (le chemin sortant). La facture est décodée
     * et vérifiée AVANT de payer.
     *
     * @param string $bolt11
     * @return array le résultat pour l'interface
     * @throws \InvalidArgumentException facture illisible, mal signée ou expirée
     * @throws \Modules\Paiements\App\Exceptions\PayoutDesactiveException vraies clés sans ADMIN_TOKEN
     */
    public function payer(string $bolt11): array;
}
