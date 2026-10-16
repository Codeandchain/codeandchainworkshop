<?php

namespace Modules\Bitnob\App\Services\Interfaces;

use Modules\Bitnob\App\Data\FactureBitnob;
use Modules\Bitnob\App\Data\PayoutBitnob;

/**
 * Ce que l'application attend de Bitnob. Deux implémentations, choisies
 * par MOCK_PAYMENTS dans BitnobServiceProvider :
 *  • HttpBitnobClient       → la vraie API (sandbox ou production)
 *  • SimulationBitnobClient → un faux Bitnob, sans clé ni argent
 */
interface BitnobClientInterface
{
    /**
     * @return bool true pour le faux Bitnob
     */
    public function estSimulation(): bool;

    /**
     * @return string l'URL de l'API Bitnob
     */
    public function baseUrl(): string;

    /**
     * POST /api/lightning/invoices
     *
     * @param string $reference clé d'idempotence : la renvoyer rend la même facture
     * @param int $montantSats
     * @param string $description
     * @param int $expirationS
     * @return FactureBitnob
     */
    public function creerFacture(string $reference, int $montantSats, string $description, int $expirationS): FactureBitnob;

    /**
     * GET /api/lightning/invoices/:id — le filet de sécurité si un webhook se perd.
     *
     * @param string $id
     * @return FactureBitnob
     */
    public function statutFacture(string $id): FactureBitnob;

    /**
     * POST /api/lightning/pay — envoyer des sats à une facture BOLT11.
     *
     * @param string $bolt11
     * @param string $reference
     * @param int|null $montantSats lu dans la facture, pour l'affichage
     * @return PayoutBitnob
     */
    public function payer(string $bolt11, string $reference, ?int $montantSats): PayoutBitnob;

    /**
     * Les webhooks que Bitnob doit envoyer MAINTENANT. Toujours vide avec
     * la vraie API (Bitnob les envoie lui-même) ; en simulation, ce sont
     * les factures dont le délai de paiement est passé.
     *
     * @return array<int, array{corps: string, signature: string}>
     */
    public function webhooksEnAttente(): array;
}
