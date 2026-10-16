<?php

namespace Modules\Paiements\App\Services\Interfaces;

interface WebhookServiceInterface
{
    /**
     * Traite un webhook Bitnob : signature, event_id dédupliqué, facture
     * créditée une seule fois.
     *
     * @param string $corpsBrut le corps EXACT reçu (la signature porte dessus)
     * @param string|null $signature en-tête x-bitnob-signature
     * @param string $source http | simulation | rejeu
     * @return array{0: int, 1: array} [code HTTP, corps de la réponse]
     */
    public function traiter(string $corpsBrut, ?string $signature, string $source = 'http'): array;
}
