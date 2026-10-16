<?php

namespace Modules\Bitnob\App\Data;

/**
 * Une facture telle que Bitnob la décrit (vraie API ou simulation).
 */
final class FactureBitnob
{
    public function __construct(
        public readonly string $id,
        public readonly string $reference,
        public readonly ?string $paymentHash,
        public readonly string $request,      // la chaîne BOLT11
        public readonly string $status,       // pending | paid | expired | failed
        public readonly ?string $preimage,    // connue une fois payée : la preuve
        public readonly int $expireA,         // millisecondes
        public readonly ?string $description = null,
    ) {
    }
}
