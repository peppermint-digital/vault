<?php

namespace Peppermint\Vault\Rechte;

use Illuminate\Contracts\Auth\Authenticatable;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Contracts\Vaultrechte;
use Peppermint\Vault\Models\VaultSpace;

/**
 * Die Regeln aus AI Brain (`VaultSpace::canView/canEdit`), mit Rolle und
 * Recht aus `vault.rechte`:
 *
 * - persoenlicher Bereich: nur die Person selbst — auch kein Verwalter;
 * - globaler Bereich: alle lesen, schreiben mit `global_schreiben` oder als Verwalter;
 * - Raum: Mitglieder lesen, Rolle `editor` schreibt, Verwalter alles;
 * - unbekannter geteilter Bereich: niemand ausser Verwaltern.
 */
class Standardrechte implements Vaultrechte
{
    public function istVerwalter(Authenticatable $user): bool
    {
        $rolle = (string) config('vault.rechte.verwalterrolle', '');

        return $rolle !== '' && method_exists($user, 'hasRole') && (bool) $user->hasRole($rolle);
    }

    public function darfLesen(Authenticatable $user, Bereich $bereich): bool
    {
        if ($bereich->istPersoenlich()) {
            return $this->istEigen($user, $bereich);
        }

        if ($this->istVerwalter($user) || $bereich->istGleich(Bereich::global())) {
            return true;
        }

        return $this->raum($bereich)?->isMember($user) ?? false;
    }

    public function darfSchreiben(Authenticatable $user, Bereich $bereich): bool
    {
        if ($bereich->istPersoenlich()) {
            return $this->istEigen($user, $bereich);
        }

        if ($this->istVerwalter($user)) {
            return true;
        }

        if ($bereich->istGleich(Bereich::global())) {
            $recht = (string) config('vault.rechte.global_schreiben', '');

            return $recht !== '' && method_exists($user, 'can') && (bool) $user->can($recht);
        }

        return $this->raum($bereich)?->roleFor($user) === VaultSpace::ROLLE_BEARBEITEN;
    }

    public function sichtbareBereiche(Authenticatable $user): array
    {
        $bereiche = [Bereich::person((int) $user->getAuthIdentifier())];

        foreach (VaultSpace::query()->visibleTo($user)->orderByDesc('is_global')->orderBy('name')->get() as $raum) {
            $bereiche[] = $raum->bereich();
        }

        return $bereiche;
    }

    private function istEigen(Authenticatable $user, Bereich $bereich): bool
    {
        return (string) $user->getAuthIdentifier() === $bereich->schluessel;
    }

    private function raum(Bereich $bereich): ?VaultSpace
    {
        $praefix = trim((string) config('vault.bereiche.raeume', '_spaces'), '/').'/';

        if (! str_starts_with($bereich->schluessel, $praefix)) {
            return null;
        }

        return VaultSpace::query()
            ->where('is_global', false)
            ->where('slug', substr($bereich->schluessel, strlen($praefix)))
            ->first();
    }
}
