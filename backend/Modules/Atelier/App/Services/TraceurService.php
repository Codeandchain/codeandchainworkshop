<?php

namespace Modules\Atelier\App\Services;

use Modules\Atelier\App\Repositories\Interfaces\EchangeRepositoryInterface;
use Modules\Atelier\App\Repositories\Interfaces\JournalRepositoryInterface;
use Modules\Core\App\Helpers\Horloge;
use Modules\Core\App\Services\Interfaces\TraceurInterface;

/**
 * Le vrai traceur : journal (interface classique) et fil des échanges
 * (interface Banc), en SQLite. Tout ceci part au navigateur : les
 * signatures y sont tronquées et aucun secret n'y entre.
 */
class TraceurService implements TraceurInterface
{
    private const MAX_JOURNAL = 40;

    private const MAX_ECHANGES = 80;

    /**
     * TraceurService constructor.
     *
     * @param JournalRepositoryInterface $journal
     * @param EchangeRepositoryInterface $echanges
     */
    public function __construct(
        protected JournalRepositoryInterface $journal,
        protected EchangeRepositoryInterface $echanges,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function noter(string $type, string $titre, string $detail = '', bool $ok = true): void
    {
        $this->journal->ajouter(['at' => Horloge::maintenant(), 'type' => $type, 'titre' => $titre, 'detail' => $detail, 'ok' => $ok], self::MAX_JOURNAL);
    }

    /**
     * @inheritdoc
     */
    public function echange(array $echange): void
    {
        if (! config('atelier.outils_demo')) {
            return; // DEMO_TOOLS=false : pas de fil
        }
        $this->echanges->ajouter(array_filter($echange, fn ($v) => $v !== null), Horloge::maintenant(), self::MAX_ECHANGES);
    }
}
