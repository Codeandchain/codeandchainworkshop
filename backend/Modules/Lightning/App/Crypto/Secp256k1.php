<?php

namespace Modules\Lightning\App\Crypto;

use phpseclib3\Crypt\EC\Curves\secp256k1 as Courbe;
use phpseclib3\Math\BigInteger;

/**
 * secp256k1 — la courbe de Bitcoin et de Lightning.
 *
 * BOLT11 a besoin d'une signature « récupérable » : 64 octets (r, s)
 * + 1 octet de recovery id, qui permet de RETROUVER la clé publique
 * du nœud à partir de la signature seule.
 *
 * Les calculs sur la courbe viennent de phpseclib, qui fonctionne en
 * PHP pur (avec GMP ou BCMath s'ils sont là, sans sinon) : parfait pour
 * un hébergement mutualisé. Le nonce est déterministe (RFC 6979) et s
 * est « bas », comme @noble/curves côté JavaScript : pour un même
 * message et une même clé, les deux produisent la même signature.
 */
class Secp256k1
{
    private static ?Courbe $courbe = null;

    private static function courbe(): Courbe
    {
        return self::$courbe ??= new Courbe();
    }

    private static function n(): BigInteger
    {
        return self::courbe()->getOrder();
    }

    private static function p(): BigInteger
    {
        return self::courbe()->getModulo();
    }

    private static function entier(string $octets): BigInteger
    {
        return new BigInteger($octets, 256);
    }

    private static function octets32(BigInteger $x): string
    {
        return str_pad($x->toBytes(), 32, "\0", STR_PAD_LEFT);
    }

    private static function modulo(BigInteger $x, BigInteger $m): BigInteger
    {
        [, $reste] = $x->divide($m);

        return $reste->isNegative() ? $reste->add($m) : $reste;
    }

    /** Clé publique compressée (33 octets, en hex) d'une clé privée de 32 octets. */
    public static function clePublique(string $clePrivee): string
    {
        $courbe = self::courbe();
        [$x, $y] = $courbe->multiplyPoint($courbe->getBasePoint(), self::entier($clePrivee));

        return self::compresser($x->toBigInteger(), $y->toBigInteger());
    }

    private static function compresser(BigInteger $x, BigInteger $y): string
    {
        return ($y->isOdd() ? '03' : '02') . bin2hex(self::octets32($x));
    }

    /**
     * Signe une empreinte de 32 octets.
     *
     * @return array{0: string, 1: int} [64 octets r‖s, recovery id 0..3]
     */
    public static function signer(string $empreinte, string $clePrivee): array
    {
        $courbe = self::courbe();
        $n = self::n();
        $d = self::entier($clePrivee);
        $z = self::modulo(self::entier($empreinte), $n);

        foreach (self::noncesRfc6979($clePrivee, self::octets32($z)) as $k) {
            [$rx, $ry] = $courbe->multiplyPoint($courbe->getBasePoint(), $k);
            $rx = $rx->toBigInteger();
            $ry = $ry->toBigInteger();
            $r = self::modulo($rx, $n);
            if ($r->equals(new BigInteger(0))) {
                continue;
            }
            // s = k⁻¹ · (z + r·d) mod n
            $s = self::modulo($k->modInverse($n)->multiply($z->add($r->multiply($d))), $n);
            if ($s->equals(new BigInteger(0))) {
                continue;
            }
            $recid = ($ry->isOdd() ? 1 : 0) | ($rx->compare($n) >= 0 ? 2 : 0);

            // « s bas » (BIP 62) : si s > n/2, on prend n − s et la parité s'inverse
            [$moitie] = $n->divide(new BigInteger(2));
            if ($s->compare($moitie) > 0) {
                $s = $n->subtract($s);
                $recid ^= 1;
            }

            return [self::octets32($r) . self::octets32($s), $recid];
        }
    }

    /** RFC 6979 : nonces déterministes (HMAC-SHA256), jamais le même k pour deux messages. */
    private static function noncesRfc6979(string $x, string $h): \Generator
    {
        $n = self::n();
        $v = str_repeat("\x01", 32);
        $k = str_repeat("\x00", 32);
        $k = hash_hmac('sha256', $v . "\x00" . $x . $h, $k, true);
        $v = hash_hmac('sha256', $v, $k, true);
        $k = hash_hmac('sha256', $v . "\x01" . $x . $h, $k, true);
        $v = hash_hmac('sha256', $v, $k, true);

        while (true) {
            $v = hash_hmac('sha256', $v, $k, true);
            $candidat = self::entier($v);
            if ($candidat->compare(new BigInteger(1)) >= 0 && $candidat->compare($n) < 0) {
                yield $candidat;
            }
            $k = hash_hmac('sha256', $v . "\x00", $k, true);
            $v = hash_hmac('sha256', $v, $k, true);
        }
    }

    /**
     * Retrouve la clé publique (compressée, hex) qui a produit la signature.
     * Q = r⁻¹ · (s·R − z·G). Retourne null si la signature est impossible.
     */
    public static function recupererClePublique(string $empreinte, string $signature64, int $recid): ?string
    {
        if (strlen($signature64) !== 64 || $recid < 0 || $recid > 3) {
            return null;
        }
        $courbe = self::courbe();
        $n = self::n();
        $p = self::p();
        $r = self::entier(substr($signature64, 0, 32));
        $s = self::entier(substr($signature64, 32));
        $zero = new BigInteger(0);
        if ($r->equals($zero) || $s->equals($zero) || $r->compare($n) >= 0 || $s->compare($n) >= 0) {
            return null;
        }

        // 1) Le point R : son x vaut r (ou r + n), sa parité est dans le recid
        $x = $recid & 2 ? $r->add($n) : $r;
        if ($x->compare($p) >= 0) {
            return null;
        }
        $y2 = self::modulo($x->powMod(new BigInteger(3), $p)->add(new BigInteger(7)), $p);
        [$exposant] = $p->add(new BigInteger(1))->divide(new BigInteger(4)); // p ≡ 3 mod 4
        $y = $y2->powMod($exposant, $p);
        if (! $y->powMod(new BigInteger(2), $p)->equals($y2)) {
            return null; // x n'est pas sur la courbe
        }
        if ($y->isOdd() !== (bool) ($recid & 1)) {
            $y = $p->subtract($y);
        }
        $pointR = [$courbe->convertInteger($x), $courbe->convertInteger($y)];

        // 2) Q = (s·r⁻¹)·R + (−z·r⁻¹)·G
        $rInv = $r->modInverse($n);
        $z = self::modulo(self::entier($empreinte), $n);
        $u1 = self::modulo($s->multiply($rInv), $n);
        $u2 = self::modulo($n->subtract($z)->multiply($rInv), $n);
        $q = $courbe->convertToAffine($courbe->multiplyAddPoints(
            [$pointR, $courbe->getBasePoint()],
            [$courbe->convertInteger($u1), $courbe->convertInteger($u2)], // phpseclib attend des éléments du corps
        ));
        if (! isset($q[1])) {
            return null;
        }

        return self::compresser($q[0]->toBigInteger(), $q[1]->toBigInteger());
    }
}
