<?php

namespace Modules\Paiements\App\Services;

use Illuminate\Support\Str;
use Modules\Bitnob\App\Services\Interfaces\BitnobClientInterface;
use Modules\Core\App\Helpers\Texte;
use Modules\Core\App\Services\Interfaces\TraceurInterface;
use Modules\Lightning\App\Services\Interfaces\Bolt11CodecInterface;
use Modules\Paiements\App\Exceptions\PayoutDesactiveException;
use Modules\Paiements\App\Services\Interfaces\PayoutServiceInterface;

class PayoutService implements PayoutServiceInterface
{
    /**
     * PayoutService constructor.
     *
     * @param BitnobClientInterface $bitnob
     * @param Bolt11CodecInterface $bolt11
     * @param TraceurInterface $traceur
     */
    public function __construct(
        protected BitnobClientInterface $bitnob,
        protected Bolt11CodecInterface $bolt11,
        protected TraceurInterface $traceur,
    ) {
    }

    /**
     * Règle d'or : on DÉCODE avant de payer. Montant, destinataire,
     * expiration : tout est dans la facture.
     *
     * @inheritdoc
     */
    public function payer(string $bolt11): array
    {
        // Avec de vraies clés, jamais de payout sans code admin
        if (! $this->bitnob->estSimulation() && config('core.admin_token') === '') {
            throw new PayoutDesactiveException();
        }

        $bolt11 = trim($bolt11);
        try {
            $d = $this->bolt11->decoder($bolt11);
        } catch (\InvalidArgumentException $e) {
            $this->traceur->echange(['cle' => 'payout.decode', 'de' => 'serveur', 'vers' => 'serveur', 'type' => 'soi', 'ko' => true,
                'verifs' => [[false, 'Facture BOLT11 lisible', $e->getMessage()]]]);
            throw new \InvalidArgumentException('Facture illisible : ' . $e->getMessage());
        }

        $verifs = [
            [true, 'Checksum bech32 valide'],
            [$d['signatureValide'], 'Signature du nœud valide', Texte::abreger($d['noeud'] ?? '')],
            [! $d['expiree'], $d['expiree'] ? 'Facture expirée' : 'Facture pas expirée', date('d/m/Y H:i:s', intdiv($d['expireLe'], 1000))],
            [true, 'Montant : ' . ($d['montantSats'] ?? 'libre') . ' sats', $d['description'] ?? ''],
        ];
        $this->traceur->echange(['cle' => 'payout.decode', 'de' => 'serveur', 'vers' => 'serveur', 'type' => 'soi',
            'ko' => in_array(false, array_column($verifs, 0), true), 'verifs' => $verifs, 'bolt11' => $bolt11]);
        if ($d['expiree']) {
            throw new \InvalidArgumentException('Facture expirée : le destinataire n\'attend plus ce paiement');
        }
        if (! $d['signatureValide']) {
            throw new \InvalidArgumentException('Signature de la facture invalide');
        }

        $reference = ($this->bitnob->estSimulation() ? 'demo-payout-' : 'payout-') . substr((string) Str::uuid(), 0, 8);
        $montant = is_int($d['montantSats']) ? $d['montantSats'] : null;
        $p = $this->bitnob->payer($bolt11, $reference, $montant);

        $this->traceur->noter('payout', 'Payout envoyé', ($p->montantSats ?? '?') . " sats · frais {$p->feeSats} · {$p->status}");

        return [
            'id' => $p->id, 'status' => $p->status, 'feeSats' => $p->feeSats, 'montantSats' => $p->montantSats,
            'paymentHash' => $d['paymentHash'], 'reference' => $p->reference, 'mock' => $this->bitnob->estSimulation(),
        ];
    }
}
