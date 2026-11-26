<?php

namespace Peppermint\Vault\EuroOffice;

use Peppermint\Vault\Vault;

/**
 * Was fuer eine Art Datei ist das? — die einzige Stelle, die das entscheidet
 * (AI Brain `Services\Vault\Dateiart`, #6294).
 *
 * Text wird ZUERST geprueft: `txt` und `csv` kann der Document Server zwar
 * oeffnen, bei uns sind es Textdateien. Wer das umdreht, schiebt jede `.csv`
 * in den Tabelleneditor.
 *
 * `steckbriefMoeglich()` aus AI Brain bleibt dort — das ist eine Frage des
 * Avatars, nicht des Vaults.
 */
final class Dateiart
{
    public const TEXT = 'text';

    public const OFFICE = 'office';

    public const PDF = 'pdf';

    public const EPUB = 'epub';

    public const BILD = 'image';

    /** Weder anzeigbar noch bearbeitbar — nur herunterladen. */
    public const ANDERES = 'other';

    private const BILDER = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];

    /** Endung → Bauform, wie der Document Server sie erwartet. */
    public const DOKUMENTTYPEN = [
        'docx' => 'word', 'doc' => 'word', 'odt' => 'word', 'rtf' => 'word', 'txt' => 'word',
        'xlsx' => 'cell', 'xls' => 'cell', 'ods' => 'cell', 'csv' => 'cell',
        'pptx' => 'slide', 'ppt' => 'slide', 'odp' => 'slide',
    ];

    public static function fuer(string $pfad): string
    {
        if (Vault::istTextdatei($pfad)) {
            return self::TEXT;
        }

        $endung = strtolower(pathinfo($pfad, PATHINFO_EXTENSION));

        return match (true) {
            $endung === 'pdf' => self::PDF,
            $endung === 'epub' => self::EPUB,
            in_array($endung, self::BILDER, true) => self::BILD,
            self::istOfficeEndung($endung) => self::OFFICE,
            default => self::ANDERES,
        };
    }

    public static function istOfficeEndung(string $endung): bool
    {
        return array_key_exists(strtolower($endung), self::DOKUMENTTYPEN);
    }

    /**
     * Office-Endungen, die wirklich im Editor landen — ohne die, die bei uns Text sind.
     *
     * @return list<string>
     */
    public static function officeEndungen(): array
    {
        return array_values(array_filter(
            array_keys(self::DOKUMENTTYPEN),
            fn (string $e): bool => ! Vault::istTextdatei("x.{$e}"),
        ));
    }
}
