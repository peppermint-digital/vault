<?php

namespace Peppermint\Vault\Rechte;

use Illuminate\Contracts\Auth\Authenticatable;
use Peppermint\Vault\Contracts\Benutzeradresse;

/** Vorgabe: die Spalte `email` des Benutzermodells, ohne Gross-/Kleinschreibung. */
class EmailAdresse implements Benutzeradresse
{
    public function adresse(Authenticatable $user): ?string
    {
        $adresse = $user->email ?? null;

        return is_string($adresse) && $adresse !== '' ? mb_strtolower($adresse) : null;
    }

    public function person(string $adresse): ?Authenticatable
    {
        $modell = (string) config('vault.user_model');

        /** @var ?Authenticatable */
        return $modell::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($adresse))])->first();
    }
}
