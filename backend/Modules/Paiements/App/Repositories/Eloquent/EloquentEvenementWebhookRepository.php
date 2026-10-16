<?php

namespace Modules\Paiements\App\Repositories\Eloquent;

use Modules\Paiements\App\Entities\EvenementWebhook;
use Modules\Paiements\App\Repositories\Interfaces\EvenementWebhookRepositoryInterface;

class EloquentEvenementWebhookRepository implements EvenementWebhookRepositoryInterface
{
    public function __construct(protected EvenementWebhook $model)
    {
    }

    /**
     * @inheritdoc
     */
    public function enregistrerSiNouveau(string $eventId): bool
    {
        return $this->model->newQuery()->insertOrIgnore(['event_id' => $eventId]) > 0;
    }
}
