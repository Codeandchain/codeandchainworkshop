<?php

namespace Modules\Atelier\App\Entities;

use Illuminate\Database\Eloquent\Model;

/**
 * Un échange du fil : requête, réponse, vérification ou événement.
 */
class Echange extends Model
{
    protected $table = 'echanges';

    protected $primaryKey = 'n';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['contenu' => 'array', 'at' => 'integer'];
    }

    public function pourNavigateur(): array
    {
        return ['n' => $this->n, 'at' => $this->at, ...$this->contenu];
    }
}
