<?php

namespace Modules\Paiements\App\Repositories\Interfaces;

interface EvenementWebhookRepositoryInterface
{
    /**
     * Enregistre l'event_id s'il est nouveau (atomique : deux retries
     * simultanés → un seul passe).
     *
     * @param string $eventId
     * @return bool false si l'event_id était déjà connu
     */
    public function enregistrerSiNouveau(string $eventId): bool;
}
