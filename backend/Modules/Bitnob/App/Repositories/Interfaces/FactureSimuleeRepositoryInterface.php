<?php

namespace Modules\Bitnob\App\Repositories\Interfaces;

use Illuminate\Support\Collection;
use Modules\Core\App\Repositories\Interfaces\BaseRepositoryInterface;

interface FactureSimuleeRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Les factures non payées dont l'heure de paiement est passée.
     *
     * @param int $maintenant millisecondes
     * @return Collection
     */
    public function dues(int $maintenant): Collection;

    /**
     * Marque la facture payée, une seule fois (atomique).
     *
     * @param string $id
     * @return bool false si une autre requête l'a déjà payée
     */
    public function marquerPayee(string $id): bool;
}
