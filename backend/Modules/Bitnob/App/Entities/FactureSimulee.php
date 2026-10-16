<?php

namespace Modules\Bitnob\App\Entities;

use Illuminate\Database\Eloquent\Model;

/**
 * Une facture du faux Bitnob (simulation).
 */
class FactureSimulee extends Model
{
    protected $table = 'bitnob_simulation_factures';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['preimage'];

    protected function casts(): array
    {
        return [
            'montant_sats' => 'integer',
            'payer_a' => 'integer',
            'expire_a' => 'integer',
            'payee' => 'boolean',
        ];
    }
}
