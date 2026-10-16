<?php

namespace Modules\Lightning\Tests\Unit;

use Modules\Lightning\App\Crypto\Secp256k1;
use Modules\Lightning\App\Services\Bolt11Codec;
use PHPUnit\Framework\TestCase;

class Bolt11CodecTest extends TestCase
{
    /** Exemple officiel de la spécification BOLT11 (« 1 cup coffee »). */
    private const SPEC = 'lnbc2500u1pvjluezsp5zyg3zyg3zyg3zyg3zyg3zyg3zyg3zyg3zyg3zyg3zyg3zyg3zygspp5qqqsyqcyq5rqwzqfqqqsyqcyq5rqwzqfqqqsyqcyq5rqwzqfqypqdq5xysxxatsyp3k7enxv4jsxqzpu9qrsgquk0rl77nj30yxdy8j9vdx85fkpmdla2087ne0xh8nhedh8w27kyke0lp53ut353s06fv3qfegext0eh0ymjpf39tuven09sam30g4vgpfna3rh';

    public function test_decode_l_exemple_officiel(): void
    {
        $d = (new Bolt11Codec())->decoder(self::SPEC);

        $this->assertSame(250000, $d['montantSats']);
        $this->assertSame('1 cup coffee', $d['description']);
        $this->assertSame(60, $d['expiration']);
        $this->assertSame('0001020304050607080900010203040506070809000102030405060708090102', $d['paymentHash']);
        $this->assertSame('03e7156ae33b0a208d0744199163177e909e80176e55d97a2f221ede0f934dd9ad', $d['noeud']);
        $this->assertTrue($d['signatureValide']);
    }

    public function test_refuse_une_facture_alteree(): void
    {
        $this->expectExceptionMessage('checksum bech32 invalide');
        (new Bolt11Codec())->decoder(substr(self::SPEC, 0, -1) . 'q');
    }

    public function test_aller_retour_encoder_decoder(): void
    {
        $preimage = random_bytes(32);
        $hash = hash('sha256', $preimage);
        $cle = hash('sha256', 'test', true);

        foreach ([1, 42, 150, 1000, 100000] as $sats) {
            $d = (new Bolt11Codec())->decoder((new Bolt11Codec())->encoder($sats, $hash, bin2hex(random_bytes(32)), 'Café Touba', 600, $cle));
            $this->assertSame($sats, $d['montantSats']);
            $this->assertSame('Café Touba', $d['description']);
            $this->assertTrue($d['signatureValide']);
            $this->assertSame(Secp256k1::clePublique($cle), $d['noeud']);
            $this->assertTrue((new Bolt11Codec())->verifierPreimage(bin2hex($preimage), $d['paymentHash']));
        }
    }

    /** Même message + même clé → même signature que @noble/curves (RFC 6979, s bas). */
    public function test_signature_identique_a_noble_curves(): void
    {
        $cle = hash('sha256', 'noeud-demo-code-and-chain-dakar', true);
        [$sig, $recid] = Secp256k1::signer(hash('sha256', 'test', true), $cle);

        $this->assertSame('88f9ce6a7949fe0bdbf25822fc5bf2f289552b9fe04113031e65b2efcbc9daa20e8e0c45183b6e05306d84477e4ac636a5a0e4ea9212fb0a5c41cd2b5cabd191', bin2hex($sig));
        $this->assertSame(1, $recid);
        $this->assertSame('024eafcedfaf5c41e9a6cc88b1b64545edb26d4b1f50f1765e49a792eefe34889d', Secp256k1::clePublique($cle));
    }
}
