<?php

namespace Modules\Paiements\App\Events;

/**
 * Un webhook vient d'être traité (accepté, ignoré ou refusé). Le module
 * Atelier l'écoute pour garder le dernier webhook authentique (démo du
 * rejeu), sans que Paiements ait à le savoir.
 */
class WebhookTraite
{
    public function __construct(
        public readonly string $corpsBrut,
        public readonly ?string $signature,
        public readonly string $source,   // http | simulation | rejeu
        public readonly int $code,
    ) {
    }
}
