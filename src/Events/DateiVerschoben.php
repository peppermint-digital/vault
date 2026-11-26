<?php

namespace Peppermint\Vault\Events;

use Peppermint\Vault\Bereich;

/** Datei oder Ordner wurde umbenannt oder verschoben (innerhalb eines Bereichs). */
class DateiVerschoben
{
    public function __construct(
        public readonly Bereich $bereich,
        public readonly string $von,
        public readonly string $nach,
        public readonly bool $istOrdner,
    ) {}
}
