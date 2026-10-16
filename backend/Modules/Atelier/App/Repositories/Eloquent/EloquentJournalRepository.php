<?php

namespace Modules\Atelier\App\Repositories\Eloquent;

use Illuminate\Support\Collection;
use Modules\Atelier\App\Entities\EntreeJournal;
use Modules\Atelier\App\Repositories\Interfaces\JournalRepositoryInterface;

class EloquentJournalRepository implements JournalRepositoryInterface
{
    public function __construct(protected EntreeJournal $model)
    {
    }

    /**
     * @inheritdoc
     */
    public function ajouter(array $entree, int $garder): void
    {
        $e = $this->model->newQuery()->create($entree);
        $this->model->newQuery()->where('id', '<=', $e->id - $garder)->delete();
    }

    /**
     * @inheritdoc
     */
    public function recentes(int $limite): Collection
    {
        return $this->model->newQuery()->orderByDesc('id')->limit($limite)->get();
    }
}
