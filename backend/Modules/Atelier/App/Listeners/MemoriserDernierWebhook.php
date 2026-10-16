<?php

namespace Modules\Atelier\App\Listeners;

use Modules\Atelier\App\Repositories\Interfaces\ReglageRepositoryInterface;
use Modules\Atelier\App\Services\AtelierService;
use Modules\Paiements\App\Events\WebhookTraite;

/**
 * Garde le dernier webhook AUTHENTIQUE (accepté, pas un rejeu) pour la
 * démo « Rejouer le webhook ».
 */
class MemoriserDernierWebhook
{
    public function __construct(protected ReglageRepositoryInterface $reglages)
    {
    }

    public function handle(WebhookTraite $evenement): void
    {
        if ($evenement->code === 200 && $evenement->source !== 'rejeu') {
            $this->reglages->ecrire(AtelierService::DERNIER_WEBHOOK, ['corps' => $evenement->corpsBrut, 'signature' => $evenement->signature]);
        }
    }
}
