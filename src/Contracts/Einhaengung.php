<?php

namespace Peppermint\Vault\Contracts;

/**
 * Haengt an diesem Pfad wirklich ein fremder Speicher?
 *
 * DIE Frage der externen Quellen: Ein nicht eingehaengter Pfad sieht aus wie
 * ein LEERES Verzeichnis. Die Oberflaeche zeigte dann „keine Dateien", und ein
 * Upload landete auf der lokalen Platte unter dem Einhaengepunkt — nach dem
 * naechsten Einhaengen unsichtbar (AI Brain #6221).
 *
 * Ein Vertrag, damit Tests ihn ersetzen koennen; die Vorgabe vergleicht die
 * Geraete-Kennung mit dem Elternverzeichnis.
 */
interface Einhaengung
{
    public function istEingehaengt(string $pfad): bool;
}
