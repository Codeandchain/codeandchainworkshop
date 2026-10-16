<?php

namespace Modules\Atelier\App\Repositories\Eloquent;

use Modules\Atelier\App\Entities\Reglage;
use Modules\Atelier\App\Repositories\Interfaces\ReglageRepositoryInterface;

class EloquentReglageRepository implements ReglageRepositoryInterface
{
    public function __construct(protected Reglage $model)
    {
    }

    /**
     * @inheritdoc
     */
    public function lire(string $cle): mixed
    {
        $valeur = $this->model->newQuery()->find($cle)?->valeur;

        return $valeur === null ? null : json_decode($valeur, true);
    }

    /**
     * @inheritdoc
     */
    public function ecrire(string $cle, mixed $valeur): void
    {
        $this->model->newQuery()->updateOrCreate(['cle' => $cle], ['valeur' => json_encode($valeur)]);
    }
}
