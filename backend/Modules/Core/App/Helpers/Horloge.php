<?php

namespace Modules\Core\App\Helpers;

class Horloge
{
    /**
     * Le temps en millisecondes, comme Date.now() côté navigateur.
     */
    public static function maintenant(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
