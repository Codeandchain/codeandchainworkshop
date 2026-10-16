<?php

namespace Modules\Atelier\App\Entities;

use Illuminate\Database\Eloquent\Model;

/**
 * Une petite valeur clé → valeur (JSON).
 */
class Reglage extends Model
{
    protected $table = 'reglages';

    protected $primaryKey = 'cle';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];
}
