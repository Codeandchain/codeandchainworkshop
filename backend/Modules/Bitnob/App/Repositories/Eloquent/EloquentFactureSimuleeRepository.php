<?php

namespace Modules\Bitnob\App\Repositories\Eloquent;

use Illuminate\Support\Collection;
use Modules\Bitnob\App\Entities\FactureSimulee;
use Modules\Bitnob\App\Repositories\Interfaces\FactureSimuleeRepositoryInterface;
use Modules\Core\App\Repositories\Eloquent\EloquentRepository;

class EloquentFactureSimuleeRepository extends EloquentRepository implements FactureSimuleeRepositoryInterface
{
    public function __construct(FactureSimulee $model)
    {
        parent::__construct($model);
    }

    /**
     * @inheritdoc
     */
    public function dues(int $maintenant): Collection
    {
        return $this->model->newQuery()
            ->where('payee', false)
            ->where('payer_a', '<=', $maintenant)
            ->where('expire_a', '>', $maintenant)
            ->get();
    }

    /**
     * @inheritdoc
     */
    public function marquerPayee(string $id): bool
    {
        return $this->model->newQuery()->whereKey($id)->where('payee', false)->update(['payee' => true]) > 0;
    }
}
