<?php

namespace Peppermint\Vault\Anmerkungen;

use Illuminate\Support\Facades\Process;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Vault;
use RuntimeException;

/**
 * PDF-Anmerkungen ueber PyMuPDF (AI Brain `PdfAnnotationService`).
 *
 * Kein reines PHP kann PDF-Markierungen setzen und lesen; das macht
 * `scripts/pdf_annotations.py`. Der Pfad wird hier aufgeloest und geprueft;
 * das Skript bekommt einen vertrauenswuerdigen absoluten Pfad und eine
 * JSON-Nutzlast ueber stdin — Nutzerdaten beruehren nie die Kommandozeile.
 *
 * Abweichung von AI Brain: Nach jeder Aenderung wird eine Binaerversion
 * angelegt. PyMuPDF schreibt in die Datei selbst, und vorher war der Stand vor
 * der Markierung danach nicht mehr erreichbar.
 */
class PdfAnmerkungen
{
    public function __construct(private readonly Vault $vault) {}

    public static function verfuegbar(): bool
    {
        $python = (string) config('vault.anmerkungen.python', '');

        return $python !== '' && is_file($python) && is_file(self::skript());
    }

    public static function skript(): string
    {
        return (string) (config('vault.anmerkungen.skript') ?: dirname(__DIR__, 2).'/scripts/pdf_annotations.py');
    }

    /** @return array<string, mixed> */
    public function markieren(Bereich $bereich, string $pfad, int $seite, string $text, string $farbe = 'yellow', ?string $kommentar = null, ?string $autor = null): array
    {
        return $this->aendern($bereich, $pfad, 'highlight', ['page' => $seite, 'text' => $text, 'color' => $farbe, 'comment' => $kommentar, 'author' => $autor], $autor);
    }

    /** @return array<string, mixed> */
    public function kommentieren(Bereich $bereich, string $pfad, int $seite, string $kommentar, ?string $text = null, ?string $autor = null): array
    {
        return $this->aendern($bereich, $pfad, 'comment', ['page' => $seite, 'text' => $text, 'comment' => $kommentar, 'author' => $autor], $autor);
    }

    /** @return array{count: int, annotations: array<int, array<string, mixed>>} */
    public function lesen(Bereich $bereich, string $pfad): array
    {
        /** @var array{count: int, annotations: array<int, array<string, mixed>>} */
        return $this->ausfuehren('extract', ['path' => $this->pdf($bereich, $pfad)]);
    }

    /** @return array<string, mixed> */
    public function notizSetzen(Bereich $bereich, string $pfad, int $seite, int $index, ?string $notiz): array
    {
        return $this->aendern($bereich, $pfad, 'setnote', ['page' => $seite, 'index' => $index, 'note' => $notiz], null);
    }

    /** @return array<string, mixed> */
    public function entfernen(Bereich $bereich, string $pfad, int $seite, int $index): array
    {
        return $this->aendern($bereich, $pfad, 'remove', ['page' => $seite, 'index' => $index], null);
    }

    /**
     * @param  array<string, mixed>  $nutzlast
     * @return array<string, mixed>
     */
    private function aendern(Bereich $bereich, string $pfad, string $befehl, array $nutzlast, ?string $autor): array
    {
        $absolut = $this->pdf($bereich, $pfad);
        $antwort = $this->ausfuehren($befehl, ['path' => $absolut] + $nutzlast);

        $this->vault->versionen()->binaer($bereich, $pfad, $absolut, $autor ?? 'anmerkung');
        $this->vault->kennung()->gespeichert($bereich, $pfad, $absolut);

        return $antwort;
    }

    private function pdf(Bereich $bereich, string $pfad): string
    {
        if (strtolower(pathinfo($pfad, PATHINFO_EXTENSION)) !== 'pdf') {
            throw new RuntimeException('Keine PDF-Datei.');
        }

        // Ein Ausbruch aus dem Bereich wirft hier (403) und geht nicht als „nicht gefunden" durch.
        $absolut = $this->vault->absolut($bereich, $pfad);

        if (! is_file($absolut)) {
            throw new RuntimeException('PDF nicht gefunden.');
        }

        return $absolut;
    }

    /**
     * @param  array<string, mixed>  $nutzlast
     * @return array<string, mixed>
     */
    private function ausfuehren(string $befehl, array $nutzlast): array
    {
        if (! self::verfuegbar()) {
            throw new RuntimeException('PDF-Anmerkungen sind hier nicht eingerichtet (PyMuPDF fehlt).');
        }

        $ergebnis = Process::timeout(max(1, (int) config('vault.anmerkungen.timeout', 60)))
            ->input((string) json_encode($nutzlast, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->run([(string) config('vault.anmerkungen.python'), self::skript(), $befehl]);

        if (! $ergebnis->successful()) {
            throw new RuntimeException('PDF-Helfer fehlgeschlagen: '.trim($ergebnis->errorOutput() ?: $ergebnis->output()));
        }

        $antwort = json_decode(trim($ergebnis->output()), true);

        if (! is_array($antwort)) {
            throw new RuntimeException('PDF-Helfer lieferte keine gueltige Antwort.');
        }

        if (($antwort['ok'] ?? false) !== true) {
            throw new RuntimeException((string) ($antwort['error'] ?? 'Unbekannter Fehler im PDF-Helfer.'));
        }

        return $antwort;
    }
}
