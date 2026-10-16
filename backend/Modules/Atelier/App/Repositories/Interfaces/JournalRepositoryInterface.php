<?php

namespace Modules\Atelier\App\Repositories\Interfaces;

use Illuminate\Support\Collection;

interface JournalRepositoryInterface
{
    /**
     * Ajoute une ligne et ne garde que les $garder dernières.
     *
     * @param array $entree
     * @param int $garder
     * @return void
     */
    public function ajouter(array $entree, int $garder): void;

    /**
     * Les lignes les plus récentes d'abord.
     *
     * @param int $limite
     * @return Collection
     */
    public function recentes(int $limite): Collection;
}
