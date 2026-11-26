<?php

namespace Peppermint\Vault\Events;

use Peppermint\Vault\Bereich;

/** Datei oder Ordner liegt jetzt im Papierkorb (`papierkorbId`) oder ist ganz weg (null). */
class DateiGeloescht
{
    public function __construct(
        public readonly Bereich $bereich,
        public readonly string $pfad,
        public readonly bool $istOrdner,
        public readonly ?int $papierkorbId,
    ) {}
}
