<?php

namespace Modules\Bitnob\App\Services;

use Modules\Bitnob\App\Services\Interfaces\SignatureWebhookInterface;

class SignatureWebhook implements SignatureWebhookInterface
{
    /**
     * En simulation sans secret configuré, on signe avec un secret de démo
     * connu : le MÊME code de vérification est exercé.
     */
    private function secret(): string
    {
        return config('bitnob.webhook_secret') ?: (config('bitnob.mock') ? 'demo-webhook-secret' : '');
    }

    /**
     * @inheritdoc
     */
    public function signer(string $corpsBrut): string
    {
        return hash_hmac('sha512', $corpsBrut, $this->secret());
    }

    /**
     * @inheritdoc
     */
    public function verifier(?string $signature, string $corpsBrut): array
    {
        $secret = $this->secret();
        if ($secret === '' || ! $signature) {
            return ['ok' => false, 'raison' => 'signature ou secret webhook manquant'];
        }
        // La doc ne précise pas l'encodage (hex ou base64) : on accepte les deux.
        // hash_equals = comparaison en temps constant.
        $brute = hash_hmac('sha512', $corpsBrut, $secret, true);
        if (! hash_equals(bin2hex($brute), $signature) && ! hash_equals(base64_encode($brute), $signature)) {
            return ['ok' => false, 'raison' => 'signature invalide'];
        }
        $evenement = json_decode($corpsBrut, true);
        if (! is_array($evenement)) {
            return ['ok' => false, 'raison' => 'corps JSON illisible'];
        }

        return ['ok' => true, 'evenement' => [
            'event' => $evenement['event'] ?? $evenement['type'] ?? '',
            'eventId' => (string) ($evenement['event_id'] ?? $evenement['id'] ?? ''),
            'data' => is_array($evenement['data'] ?? null) ? $evenement['data'] : $evenement,
        ]];
    }
}
