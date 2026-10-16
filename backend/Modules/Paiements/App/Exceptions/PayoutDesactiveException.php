<?php

namespace Modules\Paiements\App\Exceptions;

/**
 * Vraies clés Bitnob sans ADMIN_TOKEN : le payout est refusé, sinon
 * n'importe qui pourrait payer avec ton solde.
 */
class PayoutDesactiveException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('payout réel désactivé : définis ADMIN_TOKEN dans .env pour l\'autoriser');
    }
}
