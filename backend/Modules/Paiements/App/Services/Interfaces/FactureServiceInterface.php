<?php

namespace Modules\Paiements\App\Services\Interfaces;

use Modules\Paiements\App\Entities\Facture;

interface FactureServiceInterface
{
    /**
     * Crée une facture chez Bitnob et l'enregistre.
     *
     * @param int $montantSats
     * @param string $description
     * @return Facture
     */
    public function creer(int $montantSats, string $description): Facture;

    /**
     * Le statut d'une facture, avec le filet de sécurité : si elle est
     * encore en attente, on revérifie chez Bitnob (webhook perdu ?).
     *
     * @param string $id
     * @return Facture|null
     */
    public function consulter(string $id): ?Facture;

    /**
     * Fait avancer le temps (PHP n'a pas de setTimeout) : factures
     * expirées, et webhooks que le faux Bitnob doit livrer maintenant.
     *
     * @return void
     */
    public function avancerLeTemps(): void;
}
