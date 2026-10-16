<?php

namespace Modules\Paiements\App\Repositories\Interfaces;

use Modules\Core\App\Repositories\Interfaces\BaseRepositoryInterface;
use Modules\Paiements\App\Entities\Facture;

interface FactureRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Retrouve une facture par son id ou sa reference (ce que porte un webhook).
     *
     * @param string|null $id
     * @param string|null $reference
     * @return Facture|null
     */
    public function trouverParIdOuReference(?string $id, ?string $reference): ?Facture;

    /**
     * Crédite la facture UNE seule fois (mise à jour atomique).
     *
     * @param string $id
     * @param string|null $preimage
     * @param int $maintenant
     * @return bool false si elle avait déjà été créditée
     */
    public function crediterUneFois(string $id, ?string $preimage, int $maintenant): bool;

    /**
     * Marque que le navigateur a vu la facture payée (une seule fois).
     *
     * @param string $id
     * @return bool
     */
    public function marquerVuePayeeParNav(string $id): bool;

    /**
     * Les factures non payées finissent leur vie.
     *
     * @param int $maintenant
     * @return int nombre de factures expirées
     */
    public function expirerEnAttente(int $maintenant): int;
}
