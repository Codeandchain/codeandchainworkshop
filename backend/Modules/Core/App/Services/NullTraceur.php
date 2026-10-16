<?php

namespace Modules\Core\App\Services;

use Modules\Core\App\Services\Interfaces\TraceurInterface;

/**
 * Traceur par défaut : ne fait rien. Le module Atelier le remplace ; si on
 * le désactive (modules_statuses.json), les paiements marchent toujours.
 */
class NullTraceur implements TraceurInterface
{
    public function noter(string $type, string $titre, string $detail = '', bool $ok = true): void
    {
        //
    }

    public function echange(array $echange): void
    {
        //
    }
}
