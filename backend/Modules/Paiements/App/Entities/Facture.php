<?php

namespace Modules\Paiements\App\Entities;

use Illuminate\Database\Eloquent\Model;

/**
 * Une facture Lightning émise par l'application.
 */
class Facture extends Model
{
    protected $table = 'factures';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'montant_sats' => 'integer',
            'expire_a' => 'integer',
            'paye_a' => 'integer',
            'traitee' => 'boolean',
            'vu_paye_par_nav' => 'boolean',
            'mock' => 'boolean',
        ];
    }

    /**
     * Le format que l'interface attend.
     */
    public function pourNavigateur(): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'paymentHash' => $this->payment_hash,
            'montantSats' => $this->montant_sats,
            'description' => $this->description,
            'paymentRequest' => $this->payment_request,
            'status' => $this->status,
            'preimage' => $this->preimage ?? '',
            'expiresAt' => $this->expire_a,
            'traitee' => $this->traitee,
            'mock' => $this->mock,
        ];
    }

    /**
     * La réponse du polling GET /api/invoices/{id}.
     */
    public function statutPourNavigateur(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'montantSats' => $this->montant_sats,
            'paymentHash' => $this->payment_hash,
            'preimage' => $this->preimage ?? '',
            'traitee' => $this->traitee,
        ];
    }
}
