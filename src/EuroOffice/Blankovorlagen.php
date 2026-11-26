<?php

namespace Peppermint\Vault\EuroOffice;

/**
 * Leere Office-Dokumente zum Anlegen neuer Dateien (AI Brain #6298).
 *
 * Eine `.xlsx` mit null Bytes ist ein kaputtes ZIP-Archiv — eine neue Tabelle
 * entsteht deshalb als Kopie einer echten, leeren Datei. Die Vorlagen liegen
 * in `stubs/office/` des Pakets, mit LibreOffice erzeugt (Skript daneben),
 * ohne Autorenfeld und ohne fremden Inhalt — die Vorlagen des Document Servers
 * (AGPL) werden bewusst nicht uebernommen.
 *
 * Angelegt wird in den Austauschformaten; geoeffnet wird alles, was
 * {@see Dateiart} als Office kennt.
 */
final class Blankovorlagen
{
    private const VORLAGEN = [
        'docx' => 'leer.docx',
        'xlsx' => 'leer.xlsx',
        'pptx' => 'leer.pptx',
    ];

    /** @return list<string> */
    public static function endungen(): array
    {
        return array_keys(self::VORLAGEN);
    }

    /** Absoluter Pfad der Vorlage — null, wenn es keine gibt oder die Datei fehlt. */
    public static function pfadFuer(string $pfad): ?string
    {
        $datei = self::VORLAGEN[strtolower(pathinfo($pfad, PATHINFO_EXTENSION))] ?? null;

        if ($datei === null) {
            return null;
        }

        $vorlage = dirname(__DIR__, 2).'/stubs/office/'.$datei;

        return is_file($vorlage) ? $vorlage : null;
    }
}
