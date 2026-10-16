<?php

namespace Modules\Core\App\Helpers;

class Texte
{
    /**
     * Raccourcit les longues chaînes (récursivement dans les tableaux) :
     * « lnbcrt1500n1p4vla70pp56s…f4a52387 ». Sert à tout ce qui part
     * au navigateur : les signatures n'y arrivent jamais en entier.
     */
    public static function abreger(mixed $valeur): mixed
    {
        if (is_string($valeur)) {
            return mb_strlen($valeur) > 48 ? mb_substr($valeur, 0, 24) . '…' . mb_substr($valeur, -8) : $valeur;
        }
        if (is_array($valeur)) {
            return array_map([self::class, 'abreger'], $valeur);
        }

        return $valeur;
    }
}
