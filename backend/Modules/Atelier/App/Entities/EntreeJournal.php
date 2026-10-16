<?php

namespace Modules\Atelier\App\Entities;

use Illuminate\Database\Eloquent\Model;

/**
 * Une ligne du journal de l'interface classique.
 */
class EntreeJournal extends Model
{
    protected $table = 'journal';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['at' => 'integer', 'ok' => 'boolean'];
    }
}
