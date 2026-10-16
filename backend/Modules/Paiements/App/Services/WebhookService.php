<?php

namespace Modules\Paiements\App\Services;

use Modules\Bitnob\App\Services\Interfaces\SignatureWebhookInterface;
use Modules\Core\App\Helpers\Horloge;
use Modules\Core\App\Helpers\Texte;
use Modules\Core\App\Services\Interfaces\TraceurInterface;
use Modules\Paiements\App\Events\WebhookTraite;
use Modules\Paiements\App\Repositories\Interfaces\EvenementWebhookRepositoryInterface;
use Modules\Paiements\App\Repositories\Interfaces\FactureRepositoryInterface;
use Modules\Paiements\App\Services\Interfaces\WebhookServiceInterface;

/**
 * Traitement d'un webhook — LE point d'entrée des paiements.
 * 3 filets de sécurité : signature vérifiée, event_id dédupliqué,
 * idempotence par facture (un paiement = un seul effet).
 */
class WebhookService implements WebhookServiceInterface
{
    /**
     * WebhookService constructor.
     *
     * @param SignatureWebhookInterface $signatures
     * @param FactureRepositoryInterface $factures
     * @param EvenementWebhookRepositoryInterface $evenements
     * @param TraceurInterface $traceur
     */
    public function __construct(
        protected SignatureWebhookInterface $signatures,
        protected FactureRepositoryInterface $factures,
        protected EvenementWebhookRepositoryInterface $evenements,
        protected TraceurInterface $traceur,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function traiter(string $corpsBrut, ?string $signature, string $source = 'http'): array
    {
        [$code, $reponse, $verifs] = $this->appliquer($corpsBrut, $signature);

        // Le fil : la requête reçue, puis les vérifications du serveur
        $corps = json_decode($corpsBrut, true);
        $this->traceur->echange([
            'cle' => $source === 'rejeu' ? 'webhook.rejeu' : ($code === 401 ? 'webhook.refuse' : 'webhook'),
            'de' => 'bitnob', 'vers' => 'serveur', 'type' => 'req', 'simule' => $source === 'simulation', 'ko' => $code >= 400,
            'http' => ['methode' => 'POST', 'url' => '/api/webhook/bitnob', 'statut' => $code],
            'entetes' => [['x-bitnob-signature', $signature ? Texte::abreger($signature) : '(absente)']],
            'corps' => Texte::abreger(is_array($corps) ? $corps : $corpsBrut),
        ]);
        $this->traceur->echange(['cle' => 'webhook.verif', 'de' => 'serveur', 'vers' => 'serveur', 'type' => 'soi', 'ko' => $code >= 400, 'verifs' => $verifs]);

        event(new WebhookTraite($corpsBrut, $signature, $source, $code));

        return [$code, $reponse];
    }

    private function appliquer(string $corpsBrut, ?string $signature): array
    {
        $verification = $this->signatures->verifier($signature, $corpsBrut);
        if (! $verification['ok']) {
            $this->traceur->noter('webhook', 'Webhook REJETÉ', $verification['raison'], false);

            return [401, ['erreur' => 'signature invalide'],
                [[false, 'Signature HMAC-SHA512 du corps brut', "{$verification['raison']} : rien n'est crédité"]]];
        }
        ['event' => $event, 'eventId' => $eventId, 'data' => $data] = $verification['evenement'];
        $sigOk = [true, 'Signature HMAC-SHA512 valide', 'comparaison en temps constant'];

        // 1) Déduplication par event_id (Bitnob renvoie jusqu'à 3 fois en retry)
        if ($eventId !== '' && ! $this->evenements->enregistrerSiNouveau($eventId)) {
            $this->traceur->noter('webhook', "Webhook $event", 'déjà vu (retry) — ignoré');

            return [200, ['ok' => true, 'dejaVu' => true],
                [$sigOk, [false, "event_id $eventId déjà traité", 'retry : réponse 200, aucun crédit']]];
        }
        $eventOk = [true, 'event_id ' . ($eventId ?: '(aucun)') . ' jamais vu'];

        // 2) On retrouve notre facture par id ou reference
        $facture = $this->factures->trouverParIdOuReference($data['id'] ?? null, $data['reference'] ?? null);
        if (! $facture) {
            $this->traceur->noter('webhook', "Webhook $event", 'facture inconnue — ignoré');

            return [200, ['ok' => true, 'ignore' => 'facture inconnue'],
                [$sigOk, $eventOk, [false, 'Facture inconnue', 'réponse 200, rien à créditer']]];
        }

        // 3) Idempotence : la facture n'est créditée qu'une fois
        if (! $this->factures->crediterUneFois($facture->id, $data['preimage'] ?? null, Horloge::maintenant())) {
            $this->traceur->noter('webhook', "Webhook $event", 'facture déjà créditée — ignoré');

            return [200, ['ok' => true, 'dejaTraitee' => true],
                [$sigOk, $eventOk, [false, 'Facture déjà créditée', 'un paiement = un seul effet']]];
        }

        $this->traceur->noter('webhook', "Webhook $event", 'facture ' . substr($facture->id, 0, 8) . " créditée — {$facture->montant_sats} sats");

        return [200, ['ok' => true, 'applique' => true],
            [$sigOk, $eventOk, [true, 'Facture créditée', "{$facture->montant_sats} sats, une seule fois"]]];
    }
}
