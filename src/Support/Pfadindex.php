<?php

namespace Peppermint\Vault\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index ueber Bereich + Pfad.
 *
 * Unter MySQL ist `scope(128) + path(1024)` in utf8mb4 laenger als die
 * 3072 Bytes, die ein Index haben darf — die Migration scheiterte in der
 * Produktion des Managers. Dort ein Praefix-Index auf den Pfad; SQLite (AI
 * Brain, Tests) kennt keine Praefixe und braucht keine.
 */
final class Pfadindex
{
    public static function anlegen(Blueprint $table, string $pfadSpalte, string $name): void
    {
        $treiber = Schema::getConnection()->getDriverName();

        if (in_array($treiber, ['mysql', 'mariadb'], true)) {
            $table->rawIndex("scope, {$pfadSpalte}(191)", $name);

            return;
        }

        $table->index(['scope', $pfadSpalte], $name);
    }
}
