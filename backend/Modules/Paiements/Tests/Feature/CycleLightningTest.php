<?php

namespace Modules\Paiements\Tests\Feature;

use Modules\Lightning\App\Services\Interfaces\Bolt11CodecInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Le cycle complet en simulation, et les règles de sécurité. */
class CycleLightningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['bitnob.mock' => true, 'bitnob.mock_delai_ms' => 0, 'core.admin_token' => '', 'bitnob.webhook_secret' => '', 'atelier.outils_demo' => true]);
    }

    private function signer(string $corps): string
    {
        return hash_hmac('sha512', $corps, 'demo-webhook-secret');
    }

    private function posterWebhook(string $corps, string $signature)
    {
        return $this->call('POST', '/api/webhook/bitnob', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_BITNOB_SIGNATURE' => $signature], $corps);
    }

    public function test_facture_payee_par_webhook_avec_preuve(): void
    {
        $facture = $this->postJson('/api/invoices', ['amount' => 42, 'description' => 'Test'])->assertCreated()->json();

        $d = app(Bolt11CodecInterface::class)->decoder($facture['paymentRequest']);
        $this->assertSame(42, $d['montantSats']);
        $this->assertTrue($d['signatureValide']);
        $this->assertSame($facture['paymentHash'], $d['paymentHash']);
        $this->assertSame('', $facture['preimage'], 'la preimage reste secrète avant paiement');

        // La requête suivante fait avancer la simulation : paiement + webhook
        $statut = $this->getJson("/api/invoices/{$facture['id']}")->assertOk()->json();
        $this->assertSame('paid', $statut['status']);
        $this->assertTrue(app(Bolt11CodecInterface::class)->verifierPreimage($statut['preimage'], $d['paymentHash']));

        $cles = collect($this->getJson('/api/echanges')->json('echanges'))->pluck('cle')->all();
        $this->assertSame(['facture.demande', 'bitnob.facture', 'bitnob.facture.rep', 'facture.reponse', 'reseau.paiement', 'webhook', 'webhook.verif', 'statut.demande', 'statut.reponse'], $cles);
    }

    public function test_webhook_mal_signe_refuse(): void
    {
        $this->posterWebhook('{"event":"x","data":{"id":"y"}}', 'deadbeef')->assertStatus(401);
    }

    public function test_webhook_rejoue_ignore(): void
    {
        $facture = $this->postJson('/api/invoices', ['amount' => 10])->json();
        $this->getJson("/api/invoices/{$facture['id']}"); // payée par la simulation

        $this->postJson('/api/demo/rejouer-webhook')->assertOk()->assertJson(['dejaVu' => true]);

        $corps = json_encode(['event' => 'btc.lightning.received.success', 'event_id' => 'autre', 'data' => ['id' => $facture['id']]]);
        $this->posterWebhook($corps, $this->signer($corps))->assertOk()->assertJson(['dejaTraitee' => true]);
    }

    public function test_payout_simule_lit_la_facture(): void
    {
        $facture = $this->postJson('/api/invoices', ['amount' => 150])->json();

        $this->postJson('/api/pay', ['invoice' => $facture['paymentRequest']])->assertOk()->assertJson(['status' => 'success', 'montantSats' => 150]);
        $this->postJson('/api/pay', ['invoice' => substr($facture['paymentRequest'], 0, -1) . 'x'])->assertStatus(400);
    }

    public function test_code_admin_exige(): void
    {
        config(['core.admin_token' => 's3cret']);
        $facture = $this->postJson('/api/invoices', ['amount' => 10])->assertCreated()->json();

        $this->getJson('/api/echanges')->assertUnauthorized();
        $this->getJson('/api/journal')->assertUnauthorized();
        $this->postJson('/api/pay', ['invoice' => $facture['paymentRequest']])->assertUnauthorized();
        $this->postJson('/api/pay', ['invoice' => $facture['paymentRequest']], ['x-admin-token' => 'faux'])->assertUnauthorized();
        $this->postJson('/api/pay', ['invoice' => $facture['paymentRequest']], ['x-admin-token' => 's3cret'])->assertOk();
    }

    public function test_vraies_cles_sans_code_admin_pas_de_payout(): void
    {
        config(['bitnob.mock' => false]);

        $this->postJson('/api/pay', ['invoice' => 'lnbc1'])->assertForbidden();
        $this->getJson('/api/info')->assertJson(['mock' => false, 'payoutActif' => false]);
    }

    public function test_outils_demo_coupes(): void
    {
        config(['atelier.outils_demo' => false]);

        $this->getJson('/api/echanges')->assertNotFound();
        $this->postJson('/api/demo/rejouer-webhook')->assertNotFound();
    }
}
