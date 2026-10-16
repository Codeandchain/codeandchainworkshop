<?php

namespace Modules\Atelier\App\Services\Interfaces;

use Illuminate\Support\Collection;

interface AtelierServiceInterface
{
    /**
     * Le mode (simulation ou réel), l'URL du webhook, la protection.
     *
     * @return array
     */
    public function info(): array;

    /**
     * Le journal, du plus récent au plus ancien.
     *
     * @return Collection
     */
    public function journal(): Collection;

    /**
     * Le fil des échanges après le n° donné.
     *
     * @param int $depuis
     * @return array
     */
    public function echanges(int $depuis): array;

    /**
     * Renvoie le dernier webhook authentique, octet pour octet.
     *
     * @return array{0: int, 1: array}|null [code, réponse], ou null s'il n'y en a pas
     */
    public function rejouerDernierWebhook(): ?array;
}
