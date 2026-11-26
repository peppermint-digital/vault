<?php

namespace Peppermint\Vault;

use Illuminate\Support\Facades\File;

/**
 * Vorschaubilder (AI Brain `Services\Vault\VorschauBilder`, #6222).
 *
 * Zwei Regeln: nie in den Vault schreiben (Ablage unter `vault.vorschau.pfad`,
 * ausserhalb der Disk), und der Schluessel aendert sich mit dem Inhalt
 * (Aenderungszeit + Groesse), sonst zeigt die Oberflaeche nach einer Aenderung
 * das alte Bild. Ohne GD gibt es keine Vorschau, aber auch keinen Fehler.
 */
class Vorschaubilder
{
    private const KANTE = 320;

    /** Was GD lesen kann. Kamera-Rohdaten, psd, ai, eps bewusst nicht: lieber ein ehrliches Symbol. */
    public const LESBAR = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    /** Zeichnet der Browser selbst; verkleinern wuerde es groesser machen. */
    public const DIREKT = ['svg'];

    public static function istVorschaufaehig(string $endung): bool
    {
        $endung = strtolower($endung);

        return in_array($endung, self::LESBAR, true) || in_array($endung, self::DIREKT, true);
    }

    public static function basisPfad(): string
    {
        return rtrim((string) (config('vault.vorschau.pfad') ?: storage_path('app/vault-thumbs')), '/');
    }

    /** @return string|null absoluter Pfad, oder null, wenn es keine Vorschau gibt */
    public function vorschau(Bereich $bereich, string $relativ, string $absolut): ?string
    {
        if (! is_file($absolut)) {
            return null;
        }

        $endung = strtolower(pathinfo($relativ, PATHINFO_EXTENSION));

        if (in_array($endung, self::DIREKT, true)) {
            return $absolut;
        }

        if (! in_array($endung, self::LESBAR, true) || ! function_exists('imagecreatefromstring')) {
            return null;
        }

        $ziel = self::basisPfad().'/'.hash('sha256', implode('|', [$bereich->scope(), $relativ, (string) @filemtime($absolut), (string) @filesize($absolut)])).'.jpg';

        return is_file($ziel) ? $ziel : $this->erzeugen($absolut, $ziel);
    }

    private function erzeugen(string $quelle, string $ziel): ?string
    {
        $bild = @imagecreatefromstring((string) @file_get_contents($quelle));

        if ($bild === false) {
            return null;
        }

        $faktor = min(1.0, self::KANTE / max(imagesx($bild), imagesy($bild)));
        $klein = imagescale($bild, max(1, (int) round(imagesx($bild) * $faktor)), max(1, (int) round(imagesy($bild) * $faktor)));

        if ($klein === false) {
            return null;
        }

        // JPEG kennt keine Transparenz: auf Weiss legen, sonst wird der Hintergrund schwarz.
        $flach = imagecreatetruecolor(imagesx($klein), imagesy($klein));
        imagefill($flach, 0, 0, imagecolorallocate($flach, 255, 255, 255));
        imagecopy($flach, $klein, 0, 0, 0, 0, imagesx($klein), imagesy($klein));

        File::ensureDirectoryExists(dirname($ziel), 0775, true);

        return imagejpeg($flach, $ziel, 78) ? $ziel : null;
    }
}
