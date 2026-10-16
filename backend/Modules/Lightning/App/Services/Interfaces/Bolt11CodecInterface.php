<?php

namespace Modules\Lightning\App\Services\Interfaces;

interface Bolt11CodecInterface
{
    /**
     * Décode une facture BOLT11 et vérifie sa signature.
     *
     * @param string $facture
     * @return array{reseau: string, reseauNom: string, montantMsats: ?int, montantSats: float|int|null,
     *               creeLe: int, expiration: int, expireLe: int, expiree: bool, description: ?string,
     *               paymentHash: ?string, paymentSecret: ?string, noeud: ?string, signatureValide: bool}
     * @throws \InvalidArgumentException si la facture est illisible
     */
    public function decoder(string $facture): array;

    /**
     * Fabrique une facture signée. Valide pour tout décodeur, mais
     * payable seulement si le nœud qui la signe existe vraiment.
     *
     * @param int $montantSats
     * @param string $paymentHash hex, 32 octets
     * @param string $paymentSecret hex, 32 octets
     * @param string $description
     * @param int $expiration secondes
     * @param string $clePrivee 32 octets bruts
     * @param string $reseau bc | tb | bcrt | tbs
     * @return string
     */
    public function encoder(int $montantSats, string $paymentHash, string $paymentSecret, string $description, int $expiration, string $clePrivee, string $reseau = 'bcrt'): string;

    /**
     * La preuve de paiement : sha256(preimage) == payment_hash.
     *
     * @param string|null $preimageHex
     * @param string|null $paymentHashHex
     * @return bool
     */
    public function verifierPreimage(?string $preimageHex, ?string $paymentHashHex): bool;
}
