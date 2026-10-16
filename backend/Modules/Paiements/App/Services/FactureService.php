<?php

namespace Modules\Paiements\App\Services;

use Illuminate\Support\Str;
use Modules\Bitnob\App\Services\Interfaces\BitnobClientInterface;
use Modules\Core\App\Helpers\Horloge;
use Modules\Core\App\Helpers\Texte;
use Modules\Core\App\Services\Interfaces\TraceurInterface;
use Modules\Paiements\App\Entities\Facture;
use Modules\Paiements\App\Repositories\Interfaces\FactureRepositoryInterface;
use Modules\Paiements\App\Services\Interfaces\FactureServiceInterface;
use Modules\Paiements\App\Services\Interfaces\WebhookServiceInterface;

class FactureService implements FactureServiceInterface
{
    /**
     * FactureService constructor.
     *
     * @param BitnobClientInterface $bitnob
     * @param FactureRepositoryInterface $factures
     * @param WebhookServiceInterface $webhooks
     * @param TraceurInterface $traceur
     */
    public function __construct(
        protected BitnobClientInterface $bitnob,
        protected FactureRepositoryInterface $factures,
        protected WebhookServiceInterface $webhooks,
        protected TraceurInterface $traceur,
    ) {
    }

    /**
     * Une facture = une demande de paiement signée, que n'importe quel
     * wallet peut payer : un hash, un montant, une durée de vie.
     *
     * @inheritdoc
     */
    public function creer(int $montantSats, string $description): Facture
    {
        $reference = 'demo-' . substr((string) Str::uuid(), 0, 8);
        $f = $this->bitnob->creerFacture($reference, $montantSats, $description, (int) config('bitnob.expiration_s'));

        /** @var Facture $facture */
        $facture = $this->factures->create([
            'id' => $f->id, 'reference' => $f->reference, 'payment_hash' => $f->paymentHash,
            'montant_sats' => $montantSats, 'description' => $f->description ?? $description,
            'payment_request' => $f->request, 'status' => $f->status, 'preimage' => $f->preimage,
            'expire_a' => $f->expireA, 'mock' => $this->bitnob->estSimulation(),
        ]);

        $this->traceur->noter('facture', 'Facture créée', "{$facture->montant_sats} sats — « {$facture->description} »");

        return $facture;
    }

    /**
     * @inheritdoc
     */
    public function consulter(string $id): ?Facture
    {
        /** @var Facture|null $facture */
        $facture = $this->factures->find($id);
        if (! $facture) {
            return null;
        }

        if ($facture->status === 'pending') {
            $statut = $this->bitnob->statutFacture($facture->id);
            if ($statut->status === 'paid' && $this->factures->crediterUneFois($facture->id, $statut->preimage, Horloge::maintenant())) {
                // Le webhook s'est perdu ? On crédite quand même, une seule fois.
                $this->traceur->noter('facture', 'Paiement confirmé (polling)', 'facture ' . substr($facture->id, 0, 8));
                $this->traceur->echange(['cle' => 'polling.confirme', 'de' => 'serveur', 'vers' => 'serveur', 'type' => 'soi',
                    'verifs' => [[true, 'Bitnob dit « paid » alors qu\'aucun webhook n\'est arrivé', 'filet de sécurité : crédité une seule fois']]]);
            } elseif (in_array($statut->status, ['expired', 'failed'], true)) {
                $this->factures->update($facture->id, ['status' => $statut->status]);
            }
            $facture->refresh();
        }

        // Le polling tourne chaque seconde : dans le fil, on ne montre que
        // le moment où le navigateur découvre que c'est payé.
        if ($facture->status === 'paid' && $this->factures->marquerVuePayeeParNav($facture->id)) {
            $url = '/api/invoices/' . substr($facture->id, 0, 8) . '…';
            $this->traceur->echange(['cle' => 'statut.demande', 'de' => 'nav', 'vers' => 'serveur', 'type' => 'req', 'http' => ['methode' => 'GET', 'url' => $url]]);
            $this->traceur->echange(['cle' => 'statut.reponse', 'de' => 'serveur', 'vers' => 'nav', 'type' => 'rep',
                'http' => ['methode' => 'GET', 'url' => $url, 'statut' => 200],
                'corps' => Texte::abreger(['status' => 'paid', 'preimage' => $facture->preimage])]);
        }

        return $facture;
    }

    /**
     * @inheritdoc
     */
    public function avancerLeTemps(): void
    {
        $this->factures->expirerEnAttente(Horloge::maintenant());

        // Simulation : le faux Bitnob « envoie » ses webhooks, qui passent
        // par le MÊME traitement qu'un vrai POST de Bitnob.
        foreach ($this->bitnob->webhooksEnAttente() as $webhook) {
            $this->webhooks->traiter($webhook['corps'], $webhook['signature'], 'simulation');
        }
    }
}
