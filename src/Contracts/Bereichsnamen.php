<?php

namespace Peppermint\Vault\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Bereichsnamen\StandardNamen;

/**
 * Wie ein Bereich in Anfragen heisst (`?scope=`) — in beide Richtungen.
 *
 * Die Vorgabe {@see StandardNamen} kennt
 * `private`, Raum-Slugs und (mit `vault.quellen`) `extern:<slug>`. Ein Produkt
 * meldet dort weitere Praefixe an, statt den Vertrag nachzubauen — der Manager
 * etwa `objekt:<morph>:<id>` fuer die Objekt-Bereiche (V3, W1).
 *
 * Rechte entscheidet dieser Vertrag NICHT. Ein Name, der zu einem Bereich
 * fuehrt, heisst nicht, dass die Person ihn sehen darf; das fragt
 * `Bereichswahl` danach bei {@see Vaultrechte}.
 */
interface Bereichsnamen
{
    /** null = diesen Namen gibt es nicht (die Schiene antwortet 404). */
    public function aus(string $name, Authenticatable $person): ?Bereich;

    /** Der Name, unter dem die Oberflaeche den Bereich anspricht; null = nicht ansprechbar. */
    public function name(Bereich $bereich): ?string;

    /** Was die Oberflaeche als Ueberschrift zeigt. */
    public function bezeichnung(Bereich $bereich): string;
}
