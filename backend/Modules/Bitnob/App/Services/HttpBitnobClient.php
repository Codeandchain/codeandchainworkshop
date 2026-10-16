<?php

namespace Modules\Bitnob\App\Services;

use Illuminate\Support\Facades\Http;
use Modules\Bitnob\App\Data\FactureBitnob;
use Modules\Bitnob\App\Data\PayoutBitnob;
use Modules\Bitnob\App\Services\Interfaces\BitnobClientInterface;
use Modules\Core\App\Helpers\Horloge;
use Modules\Core\App\Helpers\Texte;
use Modules\Core\App\Services\Interfaces\TraceurInterface;

/**
 * La VRAIE API Bitnob (sandbox ou production).
 *
 *  • Auth HMAC : X-Auth-Client, X-Auth-Timestamp, X-Auth-Nonce, X-Auth-Signature
 *    message = CLIENT_ID:TIMESTAMP:NONCE:PAYLOAD, HMAC-SHA256 hex avec la SECRET KEY
 *  • Créer   : POST /api/lightning/invoices { reference, amount(sats), description, expiry }
 *  • Statut  : GET  /api/lightning/invoices/:id → data.status ∈ pending|paid|expired|failed
 *  • Payer   : POST /api/lightning/pay { invoice, reference } (corps à confirmer, voir README)
 */
class HttpBitnobClient implements BitnobClientInterface
{
    /**
     * @var TraceurInterface
     */
    protected $traceur;

    /**
     * HttpBitnobClient constructor.
     *
     * @param TraceurInterface $traceur
     */
    public function __construct(TraceurInterface $traceur)
    {
        $this->traceur = $traceur;
    }

    /**
     * @inheritdoc
     */
    public function estSimulation(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function baseUrl(): string
    {
        return config('bitnob.base_url');
    }

    /**
     * @inheritdoc
     */
    public function creerFacture(string $reference, int $montantSats, string $description, int $expirationS): FactureBitnob
    {
        $f = $this->appeler('POST', '/api/lightning/invoices', [
            'reference' => $reference, 'amount' => $montantSats, 'description' => $description, 'expiry' => $expirationS,
        ], 'facture');
        $f = $f['data'] ?? $f;

        return $this->versFacture($f, $reference, Horloge::maintenant() + $expirationS * 1000);
    }

    /**
     * @inheritdoc
     */
    public function statutFacture(string $id): FactureBitnob
    {
        // Pas de traçage : le polling appelle ceci chaque seconde
        $f = $this->appeler('GET', '/api/lightning/invoices/' . rawurlencode($id));

        return $this->versFacture($f['data'] ?? $f, '', Horloge::maintenant());
    }

    /**
     * @inheritdoc
     */
    public function payer(string $bolt11, string $reference, ?int $montantSats): PayoutBitnob
    {
        $f = $this->appeler('POST', '/api/lightning/pay', ['invoice' => $bolt11, 'reference' => $reference], 'pay');
        $f = $f['data'] ?? $f;

        return new PayoutBitnob(
            id: isset($f['id']) ? (string) $f['id'] : null,
            status: $f['status'] ?? 'success',
            feeSats: (int) ($f['fee_sat'] ?? $f['fees'] ?? 0),
            montantSats: isset($f['amount_sat']) ? (int) $f['amount_sat'] : $montantSats,
            reference: $f['reference'] ?? $reference,
        );
    }

    /**
     * @inheritdoc
     */
    public function webhooksEnAttente(): array
    {
        return []; // le vrai Bitnob appelle /api/webhook/bitnob lui-même
    }

    private function versFacture(array $f, string $reference, int $expireParDefaut): FactureBitnob
    {
        $expire = isset($f['expires_at']) ? strtotime($f['expires_at']) : false;

        return new FactureBitnob(
            id: (string) ($f['id'] ?? ''),
            reference: $f['reference'] ?? $reference,
            paymentHash: $f['payment_hash'] ?? null,
            request: $f['request'] ?? '',
            status: $f['status'] ?? 'pending',
            preimage: $f['preimage'] ?? null,
            expireA: $expire ? $expire * 1000 : $expireParDefaut,
            description: $f['description'] ?? null,
        );
    }

    /**
     * HMAC = « je prouve que je détiens la clé, sans l'envoyer ».
     * Le nonce (aléatoire) empêche de rejouer une requête capturée.
     */
    private function enTetesAuth(string $payload): array
    {
        $clientId = config('bitnob.client_id');
        $secret = config('bitnob.secret_key');
        if (! $clientId || ! $secret) {
            throw new \RuntimeException('BITNOB_CLIENT_ID / BITNOB_SECRET_KEY manquants dans .env');
        }
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));

        return [
            'Content-Type' => 'application/json',
            'X-Auth-Client' => $clientId,
            'X-Auth-Timestamp' => $timestamp,
            'X-Auth-Nonce' => $nonce,
            'X-Auth-Signature' => hash_hmac('sha256', implode(':', [$clientId, $timestamp, $nonce, $payload]), $secret),
        ];
    }

    /**
     * Appel à l'API (timeout 10 s). `$cle` : nom de l'échange dans le fil ;
     * sans cle, pas de traçage. La clé secrète n'est jamais tracée.
     */
    private function appeler(string $methode, string $chemin, ?array $corps = null, ?string $cle = null): array
    {
        $brut = $corps === null ? '' : json_encode($corps, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $enTetes = $this->enTetesAuth($brut);
        $url = $this->baseUrl() . $chemin;
        if ($cle) {
            $this->traceur->echange(['cle' => "bitnob.$cle", 'de' => 'serveur', 'vers' => 'bitnob', 'type' => 'req',
                'http' => ['methode' => $methode, 'url' => $url],
                'entetes' => [
                    ['X-Auth-Client', mb_substr($enTetes['X-Auth-Client'], 0, 6) . '…'],
                    ['X-Auth-Timestamp', $enTetes['X-Auth-Timestamp']],
                    ['X-Auth-Nonce', $enTetes['X-Auth-Nonce']],
                    ['X-Auth-Signature', Texte::abreger($enTetes['X-Auth-Signature'])],
                ],
                'corps' => $corps === null ? null : Texte::abreger($corps)]);
        }

        try {
            $requete = Http::withHeaders($enTetes)->timeout(10);
            // On envoie EXACTEMENT la chaîne signée (pas un ré-encodage)
            $reponse = $brut === '' ? $requete->send($methode, $url) : $requete->withBody($brut, 'application/json')->send($methode, $url);
        } catch (\Throwable $e) {
            if ($cle) {
                $this->traceur->echange(['cle' => "bitnob.$cle.rep", 'de' => 'bitnob', 'vers' => 'serveur', 'type' => 'rep', 'ko' => true,
                    'http' => ['methode' => $methode, 'url' => $chemin, 'statut' => 'réseau'], 'corps' => ['erreur' => $e->getMessage()]]);
            }
            throw $e;
        }

        $data = $reponse->json() ?? [];
        if ($cle) {
            $this->traceur->echange(['cle' => "bitnob.$cle.rep", 'de' => 'bitnob', 'vers' => 'serveur', 'type' => 'rep', 'ko' => ! $reponse->successful(),
                'http' => ['methode' => $methode, 'url' => $chemin, 'statut' => $reponse->status()],
                'corps' => Texte::abreger($data), 'bolt11' => ($data['data'] ?? $data)['request'] ?? null]);
        }
        if (! $reponse->successful()) {
            throw new \RuntimeException("Bitnob $methode $chemin → {$reponse->status()} " . json_encode($data, JSON_UNESCAPED_UNICODE));
        }

        return $data;
    }
}
