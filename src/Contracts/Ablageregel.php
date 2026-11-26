<?php

namespace Peppermint\Vault\Contracts;

use Illuminate\Database\Eloquent\Model;
use Peppermint\Vault\Ablageregeln;
use Peppermint\Vault\Bereich;

/**
 * Wohin eine Datei zu einem Objekt gehoert („hier ablegen").
 *
 * Je Objektart eine Regel, angemeldet beim {@see Ablageregeln}.
 * Das Paket ruft sie beim Anlegen auf und zieht bei einer Umbenennung des
 * Objekts den Ordner nach.
 */
interface Ablageregel
{
    public function bereichFuer(Model $objekt): Bereich;

    /** Ordner relativ zum Bereich, ohne Schraegstrich am Anfang oder Ende. */
    public function ordnerFuer(Model $objekt): string;
}
