<?php

namespace Peppermint\Vault;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Peppermint\Vault\Events\BereichAngelegt;
use Peppermint\Vault\Events\DateiGespeichert;
use Peppermint\Vault\Events\DateiVerschoben;
use Peppermint\Vault\Events\KontoAngelegt;
use Peppermint\Vault\Models\VaultTrash;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Die Datei-Arbeit eines Bereichs — EINE Implementierung fuer persoenlich und
 * geteilt (AI Brain `VaultService`, die `*InBase`-Methoden seit #2956).
 *
 * Rechte prueft dieser Dienst NICHT. Er ist der Speicher; wer ihn aufruft
 * (Controller, MCP-Werkzeug), fragt vorher {@see Contracts\Vaultrechte}.
 */
class Vault
{
    /** Nehmen an Suche und Verweisgraph teil. */
    public const INDIZIERT = ['md', 'html'];

    /**
     * Duerfen ueber den TEXT-Weg geschrieben werden. Eine Binaerdatei, die
     * durch den Text-Weg laeuft, wird mit dem Inhalt des Textfeldes
     * ueberschrieben und ist weg (AI Brain, `.ods`, 25.09.2026). Verhindern
     * kann das nur die schreibende Stelle.
     */
    public const TEXT = ['md', 'markdown', 'html', 'htm', 'txt', 'excalidraw', 'json', 'csv'];

    public function __construct(
        private readonly Ablage $ablage,
        private readonly Versionen $versionen,
        private readonly Papierkorb $papierkorb,
        private readonly Dateikennung $kennung,
        private readonly Verweise $verweise,
    ) {}

    public static function istTextdatei(string $pfad): bool
    {
        return in_array(strtolower(pathinfo($pfad, PATHINFO_EXTENSION)), self::TEXT, true);
    }

    public function ablage(): Ablage
    {
        return $this->ablage;
    }

    public function versionen(): Versionen
    {
        return $this->versionen;
    }

    public function papierkorb(): Papierkorb
    {
        return $this->papierkorb;
    }

    public function kennung(): Dateikennung
    {
        return $this->kennung;
    }

    public function verweise(): Verweise
    {
        return $this->verweise;
    }

    // ---- Lesen ----------------------------------------------------------

    /** @return array<int, array{name: string, path: string, type: string, children?: array}> */
    public function baum(Bereich $bereich): array
    {
        $basis = $this->ablage->basis($bereich);

        return File::isDirectory($basis) ? $this->baumAus($bereich, $basis, $basis) : [];
    }

    public function lesen(Bereich $bereich, string $pfad): string
    {
        $absolut = $this->ablage->aufloesen($bereich, $pfad);

        if (! is_file($absolut)) {
            throw new NotFoundHttpException('File not found.');
        }

        return File::get($absolut);
    }

    public function existiert(Bereich $bereich, string $pfad): bool
    {
        return file_exists($this->ablage->aufloesen($bereich, $pfad));
    }

    /** Inhalts-Hash fuer optimistische Konflikterkennung; leer, wenn nicht vorhanden. */
    public function fassung(Bereich $bereich, string $pfad): string
    {
        $absolut = $this->ablage->aufloesen($bereich, $pfad);

        return is_file($absolut) ? (md5_file($absolut) ?: '') : '';
    }

    public function absolut(Bereich $bereich, string $pfad): string
    {
        return $this->ablage->aufloesen($bereich, $pfad);
    }

    /**
     * Volltextsuche ueber Dateiname und Inhalt (md, html). Nur lesend.
     *
     * @return array<array{id: string, title: string, path: string, excerpt: string, url: string}>
     */
    public function suchen(Bereich $bereich, string $suche, int $limit = 10): array
    {
        $basis = $this->ablage->basis($bereich);

        if (! File::isDirectory($basis) || trim($suche) === '') {
            return [];
        }

        $kleingeschrieben = mb_strtolower($suche);
        $praefix = (string) config('vault.suche.url_praefix', '');
        $treffer = [];

        foreach (File::allFiles($basis) as $datei) {
            if (! in_array(strtolower($datei->getExtension()), self::INDIZIERT, true)) {
                continue;
            }

            $rel = $this->ablage->relativ($basis, $datei->getPathname());

            if ($this->istVerborgen($bereich, $rel)) {
                continue;
            }

            $titel = pathinfo($rel, PATHINFO_FILENAME);
            $imTitel = str_contains(mb_strtolower($titel), $kleingeschrieben);
            $inhalt = File::get($datei->getPathname());
            $imInhalt = str_contains(mb_strtolower($inhalt), $kleingeschrieben);

            if (! $imTitel && ! $imInhalt) {
                continue;
            }

            $auszug = '';
            if ($imInhalt) {
                // mb-sicher: Byteweises Schneiden zerlegt Umlaute am Rand,
                // und kaputtes UTF-8 laesst die ganze JSON-Antwort scheitern.
                $pos = mb_stripos($inhalt, $suche) ?: 0;
                $auszug = mb_scrub('…'.trim(mb_substr($inhalt, max(0, $pos - 40), mb_strlen($suche) + 100)).'…');
                $auszug = strtolower($datei->getExtension()) === 'html'
                    ? trim(preg_replace('/\s+/', ' ', strip_tags($auszug)) ?? $auszug)
                    : (preg_replace('/[#*_`\[\]>]/', '', $auszug) ?? $auszug);
            }

            $treffer[] = [
                'id' => $rel,
                'title' => $titel,
                'path' => $rel,
                'excerpt' => $auszug,
                'url' => $praefix.urlencode($rel),
                'titleMatch' => $imTitel,
            ];
        }

        usort($treffer, fn ($a, $b) => (int) $b['titleMatch'] - (int) $a['titleMatch']);

        return array_slice(array_map(fn ($t) => array_diff_key($t, ['titleMatch' => true]), $treffer), 0, $limit);
    }

    // ---- Schreiben ------------------------------------------------------

    /** `$autor` landet in der Versionshistorie ('assistant' fuer KI-Edits). */
    public function schreiben(Bereich $bereich, string $pfad, string $inhalt, ?string $autor = null): string
    {
        $this->verlangeText($pfad);
        $absolut = $this->ablage->aufloesen($bereich, $pfad);

        File::ensureDirectoryExists(dirname($absolut), 0755, true);
        File::put($absolut, $inhalt);

        $this->versionen->text($bereich, $pfad, $inhalt, $autor);

        return $this->gespeichert($bereich, $pfad, $absolut, $autor);
    }

    /**
     * Der EINE abgesicherte Schreibweg fuer Binaerinhalt (EuroOffice-Rueckweg,
     * Wiederherstellen). Daneben schreiben, dann umbenennen: Bricht das
     * Kopieren ab, bleibt die vorhandene Datei heil.
     */
    public function binaerSchreiben(Bereich $bereich, string $pfad, string $quelleAbsolut, ?string $autor = null): string
    {
        if (! is_file($quelleAbsolut)) {
            throw new \RuntimeException('Quelldatei fehlt.');
        }

        $ziel = $this->ablage->aufloesen($bereich, $pfad);
        File::ensureDirectoryExists(dirname($ziel), 0775, true);

        $temp = $ziel.'.incoming-'.bin2hex(random_bytes(4));
        if (! @copy($quelleAbsolut, $temp)) {
            @unlink($temp);
            throw new \RuntimeException("Inhalt konnte nicht gelesen werden: {$pfad}");
        }
        if (! @rename($temp, $ziel)) {
            @unlink($temp);
            throw new \RuntimeException("Datei konnte nicht geschrieben werden: {$pfad}");
        }

        $this->versionen->binaer($bereich, $pfad, $ziel, $autor);

        return $this->gespeichert($bereich, $pfad, $ziel, $autor);
    }

    /**
     * Eine Binaerdatei wurde AM ORT geaendert (PDF-Anmerkungen: PyMuPDF schreibt
     * in die Datei selbst). Legt die Binaerversion an und laeuft dann denselben
     * Weg wie jedes Speichern: Kennung, Verweis-Cache, `DateiGespeichert`.
     * Ohne diesen Weg saehen Index und Aktivitaet des Produkts die Aenderung nie.
     */
    public function amOrtGeaendert(Bereich $bereich, string $pfad, ?string $autor = null): string
    {
        $absolut = $this->ablage->aufloesen($bereich, $pfad);

        if (! is_file($absolut)) {
            throw new NotFoundHttpException('File not found.');
        }

        $this->versionen->binaer($bereich, $pfad, $absolut, $autor);

        return $this->gespeichert($bereich, $pfad, $absolut, $autor);
    }

    /**
     * Hochladen — NIE ueberschreibend: Bei gleichem Namen wird angehaengt
     * (`name-1.pdf`). Gibt den relativen Pfad zurueck.
     */
    public function hochladen(Bereich $bereich, string $ordner, UploadedFile $datei, ?string $autor = null): string
    {
        $ordner = trim($ordner, '/') ?: trim((string) config('vault.hochladen.ohne_ordner', '_attachments'), '/');
        $name = $this->sichererName($datei->getClientOriginalName());
        $rel = $this->freierName($bereich, $ordner, $name);

        $ziel = $this->ablage->aufloesen($bereich, $rel);
        File::ensureDirectoryExists(dirname($ziel), 0775, true);
        $datei->move(dirname($ziel), basename($ziel));

        $this->gespeichert($bereich, $rel, $ziel, $autor);

        return $rel;
    }

    public function ordnerAnlegen(Bereich $bereich, string $pfad): void
    {
        $absolut = $this->ablage->aufloesen($bereich, $pfad);

        if (file_exists($absolut)) {
            throw new \RuntimeException('Path already exists.');
        }

        File::makeDirectory($absolut, 0755, true);
    }

    /** Umbenennen oder Verschieben innerhalb des Bereichs. IDs und Versionen wandern mit. */
    public function verschieben(Bereich $bereich, string $von, string $nach): void
    {
        $quelle = $this->ablage->aufloesen($bereich, $von);
        $ziel = $this->ablage->aufloesen($bereich, $nach);

        if (! file_exists($quelle)) {
            throw new NotFoundHttpException('Source not found.');
        }

        if (file_exists($ziel)) {
            throw new \RuntimeException('Destination already exists.');
        }

        File::ensureDirectoryExists(dirname($ziel), 0755, true);
        $istOrdner = is_dir($quelle);
        File::move($quelle, $ziel);

        $von = trim($von, '/');
        $nach = trim($nach, '/');
        $this->kennung->verschoben($bereich, $von, $nach);
        $this->versionen->verschoben($bereich, $von, $nach);
        $this->verweise->vergessen($bereich);

        Event::dispatch(new DateiVerschoben($bereich, $von, $nach, $istOrdner));
    }

    /** In den Papierkorb — Datei oder Ordner. */
    public function loeschen(Bereich $bereich, string $pfad, ?int $geloeschtVon = null): VaultTrash
    {
        $istOrdner = is_dir($this->ablage->aufloesen($bereich, $pfad));
        $eintrag = $this->papierkorb->ablegen($bereich, $pfad, $istOrdner, $geloeschtVon);
        $this->verweise->vergessen($bereich);

        return $eintrag;
    }

    /**
     * Wie {@see loeschen()}, sichert vorher eine letzte Textversion (#2936).
     * Fuer MCP-Loeschwerkzeuge: Der Stand bleibt ueber die Versionen erreichbar.
     */
    public function loeschenWiederherstellbar(Bereich $bereich, string $pfad, ?string $autor = null, ?int $geloeschtVon = null): VaultTrash
    {
        try {
            $this->versionen->text($bereich, $pfad, $this->lesen($bereich, $pfad), $autor);
        } catch (\Throwable) {
            // Nicht lesbar oder binaer: Der Papierkorb faengt es auf.
        }

        return $this->loeschen($bereich, $pfad, $geloeschtVon);
    }

    /** Schreibt den Stand zurueck — erzeugt selbst eine Version, also nicht zerstoerend. */
    public function versionWiederherstellen(int $id, ?string $autor = null): string
    {
        $version = $this->versionen->finden($id) ?? throw new NotFoundHttpException('Version not found.');
        $bereich = Bereich::aus($version->scope);

        if ($version->isBinary()) {
            $blob = $version->absoluteStoragePath();
            if ($blob === null || ! is_file($blob)) {
                throw new \RuntimeException("Der abgelegte Stand fehlt: {$version->storage_path}");
            }

            $this->binaerSchreiben($bereich, $version->path, $blob, $autor ?? 'restore');
        } else {
            $this->schreiben($bereich, $version->path, $version->content, $autor ?? 'restore');
        }

        return $version->path;
    }

    // ---- Konten und Bereiche -------------------------------------------

    /**
     * Legt den persoenlichen Bereich an, wenn er fehlt — strikt nur anlegen,
     * nie etwas Vorhandenes anfassen. Das Produkt haengt an `KontoAngelegt`,
     * was es darueber hinaus braucht (AI Brain: Persona, Startdateien).
     */
    public function kontoAnlegen(Authenticatable $user): bool
    {
        $bereich = Bereich::person((int) $user->getAuthIdentifier());
        $neu = $this->bereichAnlegen($bereich);

        Event::dispatch(new KontoAngelegt($user, $bereich, $neu));

        return $neu;
    }

    public function bereichAnlegen(Bereich $bereich): bool
    {
        $basis = $this->ablage->wurzel().DIRECTORY_SEPARATOR.$bereich->ordner();

        if (File::isDirectory($basis)) {
            return false;
        }

        File::makeDirectory($basis, 0755, true);
        Event::dispatch(new BereichAngelegt($bereich));

        return true;
    }

    // ---- intern ---------------------------------------------------------

    private function gespeichert(Bereich $bereich, string $pfad, string $absolut, ?string $autor): string
    {
        $pfad = trim($pfad, '/');
        $id = $this->kennung->gespeichert($bereich, $pfad, $absolut);
        $this->verweise->vergessen($bereich);

        Event::dispatch(new DateiGespeichert($bereich, $pfad, $id, $autor));

        return $pfad;
    }

    private function verlangeText(string $pfad): void
    {
        if (self::istTextdatei($pfad)) {
            return;
        }

        $endung = strtolower(pathinfo($pfad, PATHINFO_EXTENSION));

        throw new UnprocessableEntityHttpException(
            $endung === ''
                ? 'Eine Datei ohne Endung laesst sich nicht als Text speichern.'
                : ".{$endung}-Dateien lassen sich nicht als Text speichern — der Inhalt wuerde dabei verloren gehen."
        );
    }

    /** Nur in der Wurzel eines persoenlichen Bereichs (AI Brain: `CLAUDE.md`). */
    public function istVerborgen(Bereich $bereich, string $rel): bool
    {
        return $bereich->istPersoenlich()
            && in_array(str_replace('\\', '/', $rel), (array) config('vault.verborgene_wurzeldateien', []), true);
    }

    private function sichererName(string $name): string
    {
        $name = trim(preg_replace('/[^\p{L}\p{N}.\-_ ]+/u', '_', basename($name)) ?? basename($name));

        return $name !== '' && $name !== '.' ? $name : 'upload';
    }

    private function freierName(Bereich $bereich, string $ordner, string $name): string
    {
        $endung = pathinfo($name, PATHINFO_EXTENSION);
        $stamm = pathinfo($name, PATHINFO_FILENAME);
        $anhang = $endung !== '' ? '.'.$endung : '';

        $praefix = $ordner === '' ? '' : $ordner.'/';
        $kandidat = $praefix.$name;
        for ($i = 1; File::exists($this->ablage->aufloesen($bereich, $kandidat)); $i++) {
            $kandidat = "{$praefix}{$stamm}-{$i}{$anhang}";
        }

        return $kandidat;
    }

    /** @return array<int, array{name: string, path: string, type: string, children?: array}> */
    private function baumAus(Bereich $bereich, string $verzeichnis, string $basis): array
    {
        $eintraege = [];

        $ordner = File::directories($verzeichnis);
        sort($ordner);
        foreach ($ordner as $dir) {
            $eintraege[] = [
                'name' => basename($dir),
                'path' => $this->ablage->relativ($basis, $dir),
                'type' => 'folder',
                'children' => $this->baumAus($bereich, $dir, $basis),
            ];
        }

        $dateien = array_map(fn ($f) => $f->getPathname(), File::files($verzeichnis));
        sort($dateien);
        foreach ($dateien as $datei) {
            $rel = $this->ablage->relativ($basis, $datei);

            if ($this->istVerborgen($bereich, $rel)) {
                continue;
            }

            $eintraege[] = ['name' => basename($datei), 'path' => $rel, 'type' => 'file'];
        }

        return $eintraege;
    }
}
