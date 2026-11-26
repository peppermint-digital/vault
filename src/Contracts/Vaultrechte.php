<?php

namespace Peppermint\Vault\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Peppermint\Vault\Bereich;

/**
 * Wer darf was — gebunden vom Produkt.
 *
 * Das Paket kennt keine Spatie-Rollen und keine Produktrechte. Es fragt nur
 * hier. AI Brain bindet `admin` + `shared-vault.edit`, der Manager
 * `administrator` + `vault.firma.bearbeiten`.
 *
 * Eine Regel gilt fuer JEDE Bindung: Ein persoenlicher Bereich gehoert allein
 * seiner Person. Verwalter verwalten Raeume, sie lesen keine fremden
 * persoenlichen Bereiche (Tagesnotizen sind heute fuer Admins nicht lesbar,
 * und das bleibt so).
 */
interface Vaultrechte
{
    /** Darf Raeume anlegen, Mitglieder setzen, alle Raeume sehen. */
    public function istVerwalter(Authenticatable $user): bool;

    public function darfLesen(Authenticatable $user, Bereich $bereich): bool;

    public function darfSchreiben(Authenticatable $user, Bereich $bereich): bool;

    /** @return list<Bereich> */
    public function sichtbareBereiche(Authenticatable $user): array;
}
