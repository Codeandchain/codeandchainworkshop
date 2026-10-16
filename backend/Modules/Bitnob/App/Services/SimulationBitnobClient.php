<?php

namespace Modules\Bitnob\App\Services;

use Illuminate\Support\Str;
use Modules\Bitnob\App\Data\FactureBitnob;
use Modules\Bitnob\App\Data\PayoutBitnob;
use Modules\Bitnob\App\Repositories\Interfaces\FactureSimuleeRepositoryInterface;
use Modules\Bitnob\App\Services\Interfaces\BitnobClientInterface;
use Modules\Bitnob\App\Services\Interfaces\SignatureWebhookInterface;
use Modules\Core\App\Helpers\Horloge;
use Modules\Core\App\Helpers\Texte;
use Modules\Core\App\Services\Interfaces\TraceurInterface;
use Modules\Lightning\App\Services\Interfaces\Bolt11CodecInterface;

/**
 * Un FAUX Bitnob (MOCK_PAYMENTS=true) : aucune clé, aucun argent.
 *
 * Il fabrique de vraies factures BOLT11, signées par un « nœud de démo »,
 * et les « paie » après MOCK_PAYMENT_DELAY_MS. PHP n'a pas de setTimeout :
 * le paiement a lieu quand l'application demande webhooksEnAttente(), à la
 * première requête après le délai (le polling de l'interface s'en charge).
 * Pas de cron, pas de worker : parfait pour un hébergement mutualisé.
 */
class SimulationBitnobClient implements BitnobClientInterface
{
    /**
     * SimulationBitnobClient constructor.
     *
     * @param FactureSimuleeRepositoryInterface $factures
     * @param Bolt11CodecInterface $bolt11
     * @param SignatureWebhookInterface $signature
     * @param TraceurInterface $traceur
     */
    public function __construct(
        protected FactureSimuleeRepositoryInterface $factures,
        protected Bolt11CodecInterface $bolt11,
        protected SignatureWebhookInterface $signature,
        protected TraceurInterface $traceur,
    ) {
    }

    /**
     * Clé privée du « nœud de démo » : fixe, pour que sa clé publique reste
     * la même d'une facture à l'autre, comme un vrai nœud.
     */
    private function cleNoeud(): string
    {
        return hash('sha256', 'noeud-demo-code-and-chain-dakar', true);
    }

    /**
     * @inheritdoc
     */
    public function estSimulation(): bool
    {
        return true;
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
        $id = (string) Str::uuid();
        //  Comme un vrai nœud : on tire d'abord la preimage (le secret),
        //  et le payment_hash est son sha256. Payer = obtenir la preimage.
        $preimage = bin2hex(random_bytes(32));
        $paymentHash = hash('sha256', hex2bin($preimage));
        $request = $this->bolt11->encoder($montantSats, $paymentHash, bin2hex(random_bytes(32)), $description, $expirationS, $this->cleNoeud());
        $maintenant = Horloge::maintenant();

        // Le fil montre l'appel que l'on AURAIT fait — marqué « simulé »
        $this->traceur->echange(['cle' => 'bitnob.facture', 'de' => 'serveur', 'vers' => 'bitnob', 'type' => 'req', 'simule' => true,
            'http' => ['methode' => 'POST', 'url' => $this->baseUrl() . '/api/lightning/invoices'],
            'entetes' => [['X-Auth-Signature', '(simulation : aucune clé, aucun appel)']],
            'corps' => ['reference' => $reference, 'amount' => $montantSats, 'description' => $description, 'expiry' => $expirationS]]);

        $this->factures->create([
            'id' => $id, 'reference' => $reference, 'payment_hash' => $paymentHash, 'preimage' => $preimage,
            'montant_sats' => $montantSats, 'description' => $description, 'request' => $request,
            'payer_a' => $maintenant + (int) config('bitnob.mock_delai_ms'), 'expire_a' => $maintenant + $expirationS * 1000,
        ]);

        $this->traceur->echange(['cle' => 'bitnob.facture.rep', 'de' => 'bitnob', 'vers' => 'serveur', 'type' => 'rep', 'simule' => true,
            'http' => ['methode' => 'POST', 'url' => '/api/lightning/invoices', 'statut' => 201],
            'corps' => Texte::abreger(['id' => $id, 'reference' => $reference, 'payment_hash' => $paymentHash, 'request' => $request, 'status' => 'pending']),
            'bolt11' => $request]);

        return new FactureBitnob($id, $reference, $paymentHash, $request, 'pending', null, $maintenant + $expirationS * 1000, $description);
    }

    /**
     * @inheritdoc
     */
    public function statutFacture(string $id): FactureBitnob
    {
        $f = $this->factures->find($id);
        if (! $f) {
            throw new \RuntimeException("facture inconnue du faux Bitnob : $id");
        }
        $status = $f->payee ? 'paid' : (Horloge::maintenant() > $f->expire_a ? 'expired' : 'pending');

        return new FactureBitnob($f->id, $f->reference, $f->payment_hash, $f->request, $status,
            $f->payee ? $f->preimage : null, $f->expire_a, $f->description);
    }

    /**
     * @inheritdoc
     */
    public function payer(string $bolt11, string $reference, ?int $montantSats): PayoutBitnob
    {
        $this->traceur->echange(['cle' => 'bitnob.pay', 'de' => 'serveur', 'vers' => 'bitnob', 'type' => 'req', 'simule' => true,
            'http' => ['methode' => 'POST', 'url' => $this->baseUrl() . '/api/lightning/pay'],
            'entetes' => [['X-Auth-Signature', '(simulation : aucune clé, aucun appel)']],
            'corps' => Texte::abreger(['invoice' => $bolt11, 'reference' => $reference])]);
        usleep(1_200_000); // on laisse le temps de voir
        $this->traceur->echange(['cle' => 'bitnob.pay.rep', 'de' => 'bitnob', 'vers' => 'serveur', 'type' => 'rep', 'simule' => true,
            'http' => ['methode' => 'POST', 'url' => '/api/lightning/pay', 'statut' => 200],
            'corps' => ['status' => 'success', 'amount_sat' => $montantSats]]);

        return new PayoutBitnob('pay-' . substr((string) Str::uuid(), 0, 8), 'success', random_int(0, 2), $montantSats, $reference);
    }

    /**
     * Les factures dont le délai est passé sont « payées » sur le réseau, et
     * le faux Bitnob prépare le webhook qu'un vrai Bitnob aurait envoyé,
     * signé avec le même HMAC.
     *
     * @inheritdoc
     */
    public function webhooksEnAttente(): array
    {
        $webhooks = [];
        foreach ($this->factures->dues(Horloge::maintenant()) as $f) {
            if (! $this->factures->marquerPayee($f->id)) {
                continue; // une autre requête vient de la payer
            }
            $this->traceur->echange(['cle' => 'reseau.paiement', 'de' => 'bitnob', 'vers' => 'bitnob', 'type' => 'evt', 'simule' => true,
                'verifs' => [[$this->bolt11->verifierPreimage($f->preimage, $f->payment_hash), 'sha256(preimage) = payment_hash',
                    Texte::abreger($f->preimage) . ' → ' . Texte::abreger($f->payment_hash)]]]);

            $corps = json_encode([
                'event' => 'btc.lightning.received.success',
                'event_id' => "mock-{$f->id}",
                'data' => ['id' => $f->id, 'reference' => $f->reference, 'payment_hash' => $f->payment_hash, 'status' => 'paid', 'preimage' => $f->preimage],
            ], JSON_UNESCAPED_SLASHES);
            $webhooks[] = ['corps' => $corps, 'signature' => $this->signature->signer($corps)];
        }

        return $webhooks;
    }
}
