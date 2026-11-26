<?php

namespace Peppermint\Vault\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Peppermint\Vault\Verknuepfung\HatVaultDateien;

/**
 * Ein Modell, an dem Vault-Dateien haengen koennen.
 *
 * Umgesetzt durch den Trait {@see HatVaultDateien}.
 * Das Morph-Kuerzel kommt aus der Morphkarte des Produkts (`getMorphClass()`).
 */
interface Verknuepfbar
{
    public function vaultLinks(): MorphMany;

    public function getMorphClass();

    public function getKey();
}
