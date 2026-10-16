<?php

namespace Modules\Paiements\App\Entities;

use Illuminate\Database\Eloquent\Model;

/**
 * Un event_id de webhook déjà traité.
 */
class EvenementWebhook extends Model
{
    protected $table = 'evenements_webhook';

    protected $primaryKey = 'event_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];
}
