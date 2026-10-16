<?php

namespace Modules\Bitnob\App\Services\Interfaces;

interface SignatureWebhookInterface
{
    /**
     * x-bitnob-signature : HMAC-SHA512 du corps brut avec le secret du webhook.
     *
     * @param string $corpsBrut
     * @return string
     */
    public function signer(string $corpsBrut): string;

    /**
     * Vérifie la signature et lit l'événement. Fail closed : pas de
     * secret ou pas de signature → refus.
     *
     * @param string|null $signature
     * @param string $corpsBrut
     * @return array{ok: bool, raison?: string, evenement?: array{event: string, eventId: string, data: array}}
     */
    public function verifier(?string $signature, string $corpsBrut): array;
}
