<?php

namespace Modules\Bitnob\App\Data;

/**
 * Le résultat d'un paiement sortant.
 */
final class PayoutBitnob
{
    public function __construct(
        public readonly ?string $id,
        public readonly string $status,
        public readonly int $feeSats,
        public readonly ?int $montantSats,
        public readonly string $reference,
    ) {
    }
}
