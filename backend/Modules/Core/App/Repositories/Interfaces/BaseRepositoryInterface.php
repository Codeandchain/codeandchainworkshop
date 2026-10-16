<?php

namespace Modules\Core\App\Repositories\Interfaces;

use Illuminate\Database\Eloquent\Model;

interface BaseRepositoryInterface
{
    /**
     * Find a record by ID.
     *
     * @param int|string $id
     * @return Model|null
     */
    public function find($id): ?Model;

    /**
     * Create a new record.
     *
     * @param array $data
     * @return Model
     */
    public function create(array $data): Model;

    /**
     * Update a record by ID.
     *
     * @param int|string $id
     * @param array $data
     * @return bool
     */
    public function update($id, array $data): bool;
}
