<?php

namespace Modules\Atelier\App\Repositories\Eloquent;

use Illuminate\Support\Collection;
use Modules\Atelier\App\Entities\Echange;
use Modules\Atelier\App\Repositories\Interfaces\EchangeRepositoryInterface;

class EloquentEchangeRepository implements EchangeRepositoryInterface
{
    public function __construct(protected Echange $model)
    {
    }

    /**
     * @inheritdoc
     */
    public function ajouter(array $contenu, int $at, int $garder): void
    {
        $e = $this->model->newQuery()->create(['at' => $at, 'contenu' => $contenu]);
        $this->model->newQuery()->where('n', '<=', $e->n - $garder)->delete();
    }

    /**
     * @inheritdoc
     */
    public function depuis(int $n): Collection
    {
        return $this->model->newQuery()->where('n', '>', $n)->orderBy('n')->get();
    }
}
