<?php

namespace Modules\Core\App\Services\Interfaces;

/**
 * Ce que les modules racontent à l'interface. Implémenté par le module
 * Atelier : les autres modules n'en dépendent qu'à travers ce contrat.
 */
interface TraceurInterface
{
    /**
     * Une ligne du journal (interface classique).
     *
     * @param string $type facture | webhook | payout
     * @param string $titre
     * @param string $detail
     * @param bool $ok
     * @return void
     */
    public function noter(string $type, string $titre, string $detail = '', bool $ok = true): void;

    /**
     * Un échange du fil (interface Banc) : requête, réponse, vérification
     * ou événement. Les valeurs doivent déjà être abrégées (Texte::abreger).
     *
     * @param array{cle: string, de: string, vers: string, type: string} $echange
     * @return void
     */
    public function echange(array $echange): void;
}
