<?php

namespace Modules\Atelier\App\Services;

use Illuminate\Support\Collection;
use Modules\Atelier\App\Repositories\Interfaces\EchangeRepositoryInterface;
use Modules\Atelier\App\Repositories\Interfaces\JournalRepositoryInterface;
use Modules\Atelier\App\Repositories\Interfaces\ReglageRepositoryInterface;
use Modules\Atelier\App\Services\Interfaces\AtelierServiceInterface;
use Modules\Bitnob\App\Services\Interfaces\BitnobClientInterface;
use Modules\Paiements\App\Services\Interfaces\WebhookServiceInterface;

class AtelierService implements AtelierServiceInterface
{
    public const DERNIER_WEBHOOK = 'dernier_webhook';

    /**
     * AtelierService constructor.
     *
     * @param BitnobClientInterface $bitnob
     * @param WebhookServiceInterface $webhooks
     * @param JournalRepositoryInterface $journal
     * @param EchangeRepositoryInterface $echanges
     * @param ReglageRepositoryInterface $reglages
     */
    public function __construct(
        protected BitnobClientInterface $bitnob,
        protected WebhookServiceInterface $webhooks,
        protected JournalRepositoryInterface $journal,
        protected EchangeRepositoryInterface $echanges,
        protected ReglageRepositoryInterface $reglages,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function info(): array
    {
        $simulation = $this->bitnob->estSimulation();
        $protege = config('core.admin_token') !== '';

        return [
            'mock' => $simulation,
            'baseUrl' => $this->bitnob->baseUrl(),
            'webhookUrl' => url('/api/webhook/bitnob'),
            'webhooksConfigures' => $simulation || config('bitnob.webhook_secret') !== '',
            'protege' => $protege,
            'outilsDemo' => (bool) config('atelier.outils_demo'),
            'payoutActif' => $simulation || $protege,
        ];
    }

    /**
     * @inheritdoc
     */
    public function journal(): Collection
    {
        return $this->journal->recentes(40);
    }

    /**
     * @inheritdoc
     */
    public function echanges(int $depuis): array
    {
        return $this->echanges->depuis($depuis)->map->pourNavigateur()->all();
    }

    /**
     * Exactement un retry de Bitnob, ou un attaquant qui aurait intercepté
     * la requête : signature valide, mais event_id déjà connu.
     *
     * @inheritdoc
     */
    public function rejouerDernierWebhook(): ?array
    {
        $dernier = $this->reglages->lire(self::DERNIER_WEBHOOK);
        if (! $dernier) {
            return null;
        }

        return $this->webhooks->traiter($dernier['corps'], $dernier['signature'], 'rejeu');
    }
}
