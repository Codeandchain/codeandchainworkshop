<?php

namespace Modules\Paiements\App\Repositories\Eloquent;

use Modules\Core\App\Repositories\Eloquent\EloquentRepository;
use Modules\Paiements\App\Entities\Facture;
use Modules\Paiements\App\Repositories\Interfaces\FactureRepositoryInterface;

class EloquentFactureRepository extends EloquentRepository implements FactureRepositoryInterface
{
    public function __construct(Facture $model)
    {
        parent::__construct($model);
    }

    /**
     * @inheritdoc
     */
    public function trouverParIdOuReference(?string $id, ?string $reference): ?Facture
    {
        return $this->model->newQuery()
            ->where('id', (string) $id)
            ->orWhere('reference', $reference ?? "\0")
            ->first();
    }

    /**
     * @inheritdoc
     */
    public function crediterUneFois(string $id, ?string $preimage, int $maintenant): bool
    {
        $donnees = ['traitee' => true, 'status' => 'paid', 'paye_a' => $maintenant];
        if ($preimage) {
            $donnees['preimage'] = $preimage;
        }

        return $this->model->newQuery()->whereKey($id)->where('traitee', false)->update($donnees) > 0;
    }

    /**
     * @inheritdoc
     */
    public function marquerVuePayeeParNav(string $id): bool
    {
        return $this->model->newQuery()->whereKey($id)->where('vu_paye_par_nav', false)->update(['vu_paye_par_nav' => true]) > 0;
    }

    /**
     * @inheritdoc
     */
    public function expirerEnAttente(int $maintenant): int
    {
        return $this->model->newQuery()
            ->where('status', 'pending')->where('traitee', false)->where('expire_a', '<', $maintenant)
            ->update(['status' => 'expired']);
    }
}
