<?php

namespace Modules\Atelier\App\Repositories\Interfaces;

use Illuminate\Support\Collection;

interface EchangeRepositoryInterface
{
    /**
     * Ajoute un échange et ne garde que les $garder derniers.
     *
     * @param array $contenu
     * @param int $at millisecondes
     * @param int $garder
     * @return void
     */
    public function ajouter(array $contenu, int $at, int $garder): void;

    /**
     * Les échanges après le n° donné, dans l'ordre.
     *
     * @param int $n
     * @return Collection
     */
    public function depuis(int $n): Collection;
}
