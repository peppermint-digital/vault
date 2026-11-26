<?php

namespace Peppermint\Vault\Quellen;

use Peppermint\Vault\Contracts\Einhaengung;

/** Ein Einhaengepunkt liegt auf einem anderen Geraet als sein Elternverzeichnis. Braucht weder Befehle noch Rechte. */
class Geraetepruefung implements Einhaengung
{
    public function istEingehaengt(string $pfad): bool
    {
        $pfad = rtrim($pfad, '/');

        if ($pfad === '' || ! is_dir($pfad)) {
            return false;
        }

        $hier = @stat($pfad);
        $darueber = @stat(dirname($pfad));

        return $hier !== false && $darueber !== false && $hier['dev'] !== $darueber['dev'];
    }
}
