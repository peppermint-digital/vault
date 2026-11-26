<?php

namespace Peppermint\Vault\Models;

/**
 * Der Tabellenname kommt aus `vault.tables` — abbildbar je Produkt
 * (im Manager ist `note_shares` vom CRM belegt).
 */
trait Tabellenname
{
    abstract protected static function tabellenSchluessel(): string;

    public function getTable(): string
    {
        return (string) config('vault.tables.'.static::tabellenSchluessel(), parent::getTable());
    }

    public static function tabelle(): string
    {
        return (string) config('vault.tables.'.static::tabellenSchluessel());
    }
}
