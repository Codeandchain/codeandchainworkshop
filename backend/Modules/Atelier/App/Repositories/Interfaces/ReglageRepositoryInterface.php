<?php

namespace Modules\Atelier\App\Repositories\Interfaces;

interface ReglageRepositoryInterface
{
    /**
     * @param string $cle
     * @return mixed la valeur décodée, ou null
     */
    public function lire(string $cle): mixed;

    /**
     * @param string $cle
     * @param mixed $valeur encodée en JSON
     * @return void
     */
    public function ecrire(string $cle, mixed $valeur): void;
}
