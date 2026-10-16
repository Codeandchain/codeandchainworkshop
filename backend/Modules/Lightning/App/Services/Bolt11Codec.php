<?php

namespace Modules\Lightning\App\Services;

use Modules\Lightning\App\Crypto\Secp256k1;
use Modules\Lightning\App\Services\Interfaces\Bolt11CodecInterface;

/**
 * BOLT11 — lire et fabriquer une facture Lightning, côté serveur.
 *
 * Même logique que src/lib/bolt11.js (le décodeur du navigateur) :
 *
 *   lnbc 1500n 1 pjq…(données)…  xxxxxx
 *   │    │     │ │                └ checksum bech32 (6 caractères)
 *   │    │     │ └ horodatage + champs étiquetés + signature
 *   │    │     └ séparateur "1"
 *   │    └ montant (1500 nano-BTC = 150 sats)
 *   └ réseau : lnbc = mainnet, lntb = testnet, lnbcrt = regtest
 *
 * Le serveur s'en sert pour :
 *  • DÉCODER une facture avant de la payer (montant, expiration, signature)
 *  • ENCODER de vraies factures signées en mode simulation
 *
 * Référence : https://github.com/lightning/bolts/blob/master/11-payment-encoding.md
 */
class Bolt11Codec implements Bolt11CodecInterface
{
    private const ALPHABET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

    private const RESEAUX = [
        'bc' => 'mainnet (vrai bitcoin)', 'tb' => 'testnet', 'bcrt' => 'regtest (réseau local)', 'tbs' => 'signet',
    ];

    // ── bech32 ──────────────────────────────────────────────────
    private static function polymod(array $valeurs): int
    {
        $g = [0x3B6A57B2, 0x26508E6D, 0x1EA119FA, 0x3D4233DD, 0x2A1462B3];
        $chk = 1;
        foreach ($valeurs as $v) {
            $b = $chk >> 25;
            $chk = (($chk & 0x1FFFFFF) << 5) ^ $v;
            for ($i = 0; $i < 5; $i++) {
                if (($b >> $i) & 1) {
                    $chk ^= $g[$i];
                }
            }
        }

        return $chk;
    }

    private static function etendreHrp(string $hrp): array
    {
        $codes = array_map('ord', str_split($hrp));

        return array_merge(array_map(fn ($c) => $c >> 5, $codes), [0], array_map(fn ($c) => $c & 31, $codes));
    }

    private static function checksum(string $hrp, array $mots): array
    {
        $mod = self::polymod(array_merge(self::etendreHrp($hrp), $mots, [0, 0, 0, 0, 0, 0])) ^ 1;

        return array_map(fn ($i) => ($mod >> (5 * (5 - $i))) & 31, range(0, 5));
    }

    /** Conversion entre « mots » de 5 bits et octets de 8 bits. */
    private static function convertirBits(array $donnees, int $de, int $vers, bool $completer): array
    {
        $acc = 0;
        $bits = 0;
        $sortie = [];
        $max = (1 << $vers) - 1;
        foreach ($donnees as $v) {
            $acc = (($acc << $de) | $v) & 0xFFFFFF; // on ne garde que les bits utiles
            $bits += $de;
            while ($bits >= $vers) {
                $bits -= $vers;
                $sortie[] = ($acc >> $bits) & $max;
            }
        }
        if ($completer && $bits > 0) {
            $sortie[] = ($acc << ($vers - $bits)) & $max;
        }

        return $sortie;
    }

    private static function motsVersOctets(array $mots): string
    {
        return pack('C*', ...self::convertirBits($mots, 5, 8, false));
    }

    private static function octetsVersMots(string $octets): array
    {
        return self::convertirBits(array_values(unpack('C*', $octets) ?: []), 8, 5, true);
    }

    private static function motsVersEntier(array $mots): int
    {
        return array_reduce($mots, fn ($n, $m) => $n * 32 + $m, 0);
    }

    private static function entierVersMots(int $n, ?int $longueur = null): array
    {
        $mots = [];
        do {
            array_unshift($mots, $n % 32);
            $n = intdiv($n, 32);
        } while ($longueur === null ? $n > 0 : count($mots) < $longueur);

        return $mots;
    }

    /** Ce qui est signé : sha256(hrp en UTF-8 ‖ données en octets). */
    private static function empreinte(string $hrp, array $motsSignes): string
    {
        return hash('sha256', $hrp . pack('C*', ...self::convertirBits($motsSignes, 5, 8, true)), true);
    }

    /** Montant du hrp → millisats (21 M BTC tiennent dans un entier 64 bits). */
    private static function lireMontant(string $texte): ?int
    {
        if ($texte === '') {
            return null; // facture « montant libre »
        }
        if (! preg_match('/^(\d{1,15})([munp]?)$/', $texte, $m)) {
            throw new \InvalidArgumentException("montant illisible : $texte");
        }
        [, $chiffres, $unite] = $m;
        $n = (int) $chiffres;
        $msats = match ($unite) {
            '' => $n * 100_000_000_000,
            'm' => $n * 100_000_000,
            'u' => $n * 100_000,
            'n' => $n * 100,
            'p' => $n % 10 === 0
                ? intdiv($n, 10)
                : throw new \InvalidArgumentException('montant en pico-BTC non multiple de 10'),
        };
        if (! is_int($msats) || $msats > 2_100_000_000_000_000_000) {
            throw new \InvalidArgumentException('montant impossible (plus que les 21 millions de BTC)');
        }

        return $msats;
    }

    /**
     * @inheritdoc
     */
    public function decoder(string $facture): array
    {
        $brut = preg_replace('/^lightning:/i', '', trim($facture));
        if ($brut !== strtolower($brut) && $brut !== strtoupper($brut)) {
            throw new \InvalidArgumentException('casse mélangée');
        }
        $texte = strtolower($brut);

        // 1) hrp et données, séparés par le DERNIER "1"
        $sep = strrpos($texte, '1');
        if ($sep === false || $sep < 1 || ! str_starts_with($texte, 'ln')) {
            throw new \InvalidArgumentException('ce n\'est pas une facture Lightning (doit commencer par ln…)');
        }
        $hrp = substr($texte, 0, $sep);
        $mots = [];
        foreach (str_split(substr($texte, $sep + 1)) as $c) {
            $v = strpos(self::ALPHABET, $c);
            if ($v === false) {
                throw new \InvalidArgumentException("caractère interdit en bech32 : « $c »");
            }
            $mots[] = $v;
        }
        if (count($mots) < 6 + 7 + 104 || self::polymod(array_merge(self::etendreHrp($hrp), $mots)) !== 1) {
            throw new \InvalidArgumentException('checksum bech32 invalide (facture tronquée ou modifiée ?)');
        }

        // 2) réseau + montant
        if (! preg_match('/^ln(bcrt|tbs|bc|tb)(\d*[munp]?)$/', $hrp, $h)) {
            throw new \InvalidArgumentException("préfixe inconnu : $hrp");
        }
        [, $codeReseau, $texteMontant] = $h;
        $msats = self::lireMontant($texteMontant);

        // 3) horodatage (7 mots) + champs + signature (104 mots)
        $donnees = array_slice($mots, 0, -6);
        $motsSignature = array_slice($donnees, -104);
        $motsSignes = array_slice($donnees, 0, -104);
        $horodatage = self::motsVersEntier(array_slice($motsSignes, 0, 7));

        $champs = [];
        for ($i = 7; $i + 2 < count($motsSignes);) {
            $code = self::ALPHABET[$motsSignes[$i]];
            $longueur = $motsSignes[$i + 1] * 32 + $motsSignes[$i + 2];
            $champs[$code] ??= array_slice($motsSignes, $i + 3, $longueur); // le 1er de chaque type
            $i += 3 + $longueur;
        }
        $enHex = fn (string $code) => isset($champs[$code]) ? bin2hex(self::motsVersOctets($champs[$code])) : null;

        // 4) signature → clé publique du nœud émetteur
        $sig = self::motsVersOctets($motsSignature);
        $retrouvee = Secp256k1::recupererClePublique(self::empreinte($hrp, $motsSignes), substr($sig, 0, 64), ord($sig[64] ?? "\xFF"));
        $noeud = $enHex('n');
        $signatureValide = $retrouvee !== null && ($noeud === null || $noeud === $retrouvee);

        $expiration = isset($champs['x']) ? self::motsVersEntier($champs['x']) : 3600; // défaut BOLT11

        return [
            'reseau' => $codeReseau,
            'reseauNom' => self::RESEAUX[$codeReseau],
            'montantMsats' => $msats,
            'montantSats' => $msats === null ? null : ($msats % 1000 === 0 ? intdiv($msats, 1000) : $msats / 1000),
            'creeLe' => $horodatage * 1000,
            'expiration' => $expiration,
            'expireLe' => ($horodatage + $expiration) * 1000,
            'expiree' => time() > $horodatage + $expiration,
            'description' => isset($champs['d']) ? self::motsVersOctets($champs['d']) : null,
            'paymentHash' => $enHex('p'),
            'paymentSecret' => $enHex('s'),
            'noeud' => $noeud ?? $retrouvee,
            'signatureValide' => $signatureValide,
        ];
    }

    /**
     * @inheritdoc
     */
    public function encoder(int $montantSats, string $paymentHash, string $paymentSecret, string $description, int $expiration, string $clePrivee, string $reseau = 'bcrt'): string
    {
        // Montant : l'unité la plus courte qui tombe juste
        $msats = $montantSats * 1000;
        $texteMontant = match (true) {
            $msats % 100_000_000 === 0 => intdiv($msats, 100_000_000) . 'm',
            $msats % 100_000 === 0 => intdiv($msats, 100_000) . 'u',
            default => intdiv($msats, 100) . 'n',
        };
        $hrp = "ln{$reseau}{$texteMontant}";

        $champ = fn (string $code, array $mots) => [strpos(self::ALPHABET, $code), count($mots) >> 5, count($mots) & 31, ...$mots];
        $motsSignes = [
            ...self::entierVersMots(time(), 7),
            ...$champ('p', self::octetsVersMots(hex2bin($paymentHash))),
            ...$champ('s', self::octetsVersMots(hex2bin($paymentSecret))),
            ...$champ('d', self::octetsVersMots($description)),
            ...$champ('x', self::entierVersMots($expiration)),
        ];
        [$sig, $recid] = Secp256k1::signer(self::empreinte($hrp, $motsSignes), $clePrivee);
        $donnees = [...$motsSignes, ...self::octetsVersMots($sig . chr($recid))];

        return $hrp . '1' . implode('', array_map(fn ($v) => self::ALPHABET[$v], [...$donnees, ...self::checksum($hrp, $donnees)]));
    }

    /**
     * @inheritdoc
     */
    public function verifierPreimage(?string $preimageHex, ?string $paymentHashHex): bool
    {
        if (! preg_match('/^[0-9a-f]{64}$/i', (string) $preimageHex) || ! preg_match('/^[0-9a-f]{64}$/i', (string) $paymentHashHex)) {
            return false;
        }

        return hash_equals(strtolower($paymentHashHex), hash('sha256', hex2bin($preimageHex)));
    }
}
