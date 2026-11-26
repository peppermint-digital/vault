<?php

namespace Peppermint\Vault\Events;

use Peppermint\Vault\Bereich;

/**
 * Eine Datei wurde geschrieben (Text, Binaer, Hochladen, Wiederherstellen).
 *
 * AI Brain haengt Indexer und Aktivitaet daran, der Manager Aktivitaetsliste
 * und Benachrichtigungen. Das Paket selbst weiss von beidem nichts.
 */
class DateiGespeichert
{
    public function __construct(
        public readonly Bereich $bereich,
        public readonly string $pfad,
        public readonly ?string $dateiId,
        public readonly ?string $autor = null,
    ) {}
}
