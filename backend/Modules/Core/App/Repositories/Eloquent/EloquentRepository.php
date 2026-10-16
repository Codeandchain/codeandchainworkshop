<?php

namespace Modules\Core\App\Repositories\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\App\Repositories\Interfaces\BaseRepositoryInterface;

abstract class EloquentRepository implements BaseRepositoryInterface
{
    /**
     * @var Model
     */
    protected $model;

    /**
     * EloquentRepository constructor.
     *
     * @param Model $model
     */
    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    /**
     * @inheritdoc
     */
    public function find($id): ?Model
    {
        return $this->model->newQuery()->find($id);
    }

    /**
     * @inheritdoc
     */
    public function create(array $data): Model
    {
        return $this->model->newQuery()->create($data);
    }

    /**
     * @inheritdoc
     */
    public function update($id, array $data): bool
    {
        return $this->model->newQuery()->whereKey($id)->update($data) > 0;
    }
}
