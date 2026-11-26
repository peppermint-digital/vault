<?php

namespace Peppermint\Vault\Verknuepfung;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Peppermint\Vault\Models\VaultLink;

/**
 * Fuer Modelle, an denen Vault-Dateien haengen (Vertrag `Verknuepfbar`).
 *
 * Beim Loeschen des Objekts fallen nur die Verknuepfungen — die Dateien
 * bleiben im Vault. Eine Notiz ueberlebt die Aufgabe, an der sie hing.
 */
trait HatVaultDateien
{
    public static function bootHatVaultDateien(): void
    {
        static::deleted(function ($objekt): void {
            if (method_exists($objekt, 'isForceDeleting') && ! $objekt->isForceDeleting()) {
                return;
            }

            $objekt->vaultLinks()->delete();
        });
    }

    public function vaultLinks(): MorphMany
    {
        return $this->morphMany(VaultLink::class, 'linkable');
    }
}
