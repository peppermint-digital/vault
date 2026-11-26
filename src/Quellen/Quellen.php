<?php

namespace Peppermint\Vault\Quellen;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Peppermint\Vault\Ablage;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Contracts\Einhaengung;
use Peppermint\Vault\Models\VaultSource;
use Peppermint\Vault\Vault;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Datei-Arbeit auf einer extern angebundenen Quelle (AI Brain
 * `VaultService::*Source*`, #6221).
 *
 * Dieselbe Pfadsicherheit wie die eigenen Bereiche ({@see Ablage::innerhalb()}),
 * aber OHNE Versionen, Papierkorb, Datei-IDs und Index: Es ist nicht unser
 * Speicher, und ein vollstaendiger Verlauf kaeme nie zustande. Loeschen
 * loescht — es gilt, was die Freigabe vorgibt.
 *
 * Jede Operation geht durch {@see basis()}: Dort wird geprueft, dass wirklich
 * eingehaengt ist. Ohne diese Pruefung legte `innerhalb()` ein fehlendes
 * Verzeichnis AN, und ein Upload laege nach dem naechsten Einhaengen unsichtbar
 * darunter. Der Riegel greift auch, wenn die Einhaengung mitten in einer
 * Anfrage wegbricht.
 */
class Quellen
{
    public function __construct(
        private readonly Ablage $ablage,
        private readonly Einhaengung $einhaengung,
    ) {}

    public function quelle(Bereich $bereich): VaultSource
    {
        if (! $bereich->istQuelle()) {
            throw new \LogicException('Kein Quellen-Bereich.');
        }

        return VaultSource::query()->where('slug', $bereich->schluessel)->first() ?? throw new NotFoundHttpException;
    }

    /**
     * Genau EINE Ebene — nicht der ganze Baum. Ueber CIFS gemessen rund 490
     * Eintraege je Sekunde; auf einer grossen Freigabe braeuchte der ganze
     * Baum Minuten. Eine Ebene: 0,08–0,21 s.
     *
     * @return array<int, array{name: string, path: string, type: string}>
     */
    public function ebene(Bereich $bereich, string $pfad = ''): array
    {
        $basis = $this->basis($bereich);
        $verzeichnis = $this->ablage->innerhalb($basis, $pfad);

        if (! is_dir($verzeichnis)) {
            throw new NotFoundHttpException('Folder not found.');
        }

        // Beides AUFGELOEST: Ein Einhaengepunkt ist oft ein Verweis. Die Wurzel
        // kaeme sonst unaufgeloest zurueck, gekuerzt wuerde gegen den aufgeloesten
        // Pfad — und jeder Eintrag truege den halben Hostpfad im Namen.
        $wurzel = realpath($basis) ?: $basis;
        $verzeichnis = realpath($verzeichnis) ?: $verzeichnis;
        $eintraege = [];

        $ordner = File::directories($verzeichnis);
        sort($ordner);
        foreach ($ordner as $o) {
            $eintraege[] = ['name' => basename($o), 'path' => $this->ablage->relativ($wurzel, $o), 'type' => 'folder'];
        }

        $dateien = array_map(fn ($d) => $d->getPathname(), File::files($verzeichnis));
        sort($dateien);
        foreach ($dateien as $d) {
            $eintraege[] = ['name' => basename($d), 'path' => $this->ablage->relativ($wurzel, $d), 'type' => 'file'];
        }

        return $eintraege;
    }

    public function absolut(Bereich $bereich, string $pfad): string
    {
        return $this->ablage->innerhalb($this->basis($bereich), $pfad);
    }

    public function lesen(Bereich $bereich, string $pfad): string
    {
        $absolut = $this->absolut($bereich, $pfad);

        if (! is_file($absolut)) {
            throw new NotFoundHttpException('File not found.');
        }

        return File::get($absolut);
    }

    /** Schreiben OHNE Version — fremder Speicher, fremde Schreiber. */
    public function schreiben(Bereich $bereich, string $pfad, string $inhalt): void
    {
        if (! Vault::istTextdatei($pfad)) {
            throw new UnprocessableEntityHttpException('Diese Datei laesst sich nicht als Text speichern — der Inhalt wuerde verloren gehen.');
        }

        $absolut = $this->absolut($bereich, $pfad);
        File::ensureDirectoryExists(dirname($absolut), 0775, true);
        File::put($absolut, $inhalt);
    }

    public function binaerSchreiben(Bereich $bereich, string $pfad, string $quelleAbsolut): void
    {
        if (! is_file($quelleAbsolut)) {
            throw new \RuntimeException('Quelldatei fehlt.');
        }

        $ziel = $this->absolut($bereich, $pfad);
        File::ensureDirectoryExists(dirname($ziel), 0775, true);

        if (! @copy($quelleAbsolut, $ziel)) {
            throw new \RuntimeException("Datei konnte nicht geschrieben werden: {$pfad}");
        }
    }

    /** Loeschen loescht — kein Papierkorb auf fremdem Speicher. */
    public function loeschen(Bereich $bereich, string $pfad): void
    {
        $basis = $this->basis($bereich);
        $absolut = $this->ablage->innerhalb($basis, $pfad);

        if (! file_exists($absolut)) {
            throw new NotFoundHttpException;
        }

        if (is_dir($absolut)) {
            if (realpath($absolut) === realpath($basis)) {
                throw new AccessDeniedHttpException('Die Wurzel einer externen Quelle laesst sich nicht loeschen.');
            }
            File::deleteDirectory($absolut);

            return;
        }

        File::delete($absolut);
    }

    public function ordnerAnlegen(Bereich $bereich, string $pfad): void
    {
        $absolut = $this->absolut($bereich, $pfad);

        if (file_exists($absolut)) {
            throw new \RuntimeException('Path already exists.');
        }

        File::makeDirectory($absolut, 0775, true);
    }

    public function verschieben(Bereich $bereich, string $von, string $nach): void
    {
        $basis = $this->basis($bereich);
        $quelle = $this->ablage->innerhalb($basis, $von);
        $ziel = $this->ablage->innerhalb($basis, $nach);

        if (! file_exists($quelle)) {
            throw new NotFoundHttpException('Source not found.');
        }
        if (file_exists($ziel)) {
            throw new \RuntimeException('Destination already exists.');
        }

        File::ensureDirectoryExists(dirname($ziel), 0775, true);
        File::move($quelle, $ziel);
    }

    /** Nie ueberschreibend, wie in den eigenen Bereichen. */
    public function hochladen(Bereich $bereich, string $ordner, UploadedFile $datei): string
    {
        $basis = $this->basis($bereich);
        $ordner = trim($ordner, '/');
        $name = trim(preg_replace('/[^\p{L}\p{N}.\-_ ]+/u', '_', basename($datei->getClientOriginalName())) ?? '') ?: 'upload';
        $stamm = pathinfo($name, PATHINFO_FILENAME);
        $endung = pathinfo($name, PATHINFO_EXTENSION);
        $praefix = $ordner === '' ? '' : $ordner.'/';

        $kandidat = $praefix.$name;
        for ($i = 1; file_exists($this->ablage->innerhalb($basis, $kandidat)); $i++) {
            $kandidat = $praefix.$stamm.'-'.$i.($endung !== '' ? '.'.$endung : '');
        }

        $ziel = $this->ablage->innerhalb($basis, $kandidat);
        File::ensureDirectoryExists(dirname($ziel), 0775, true);
        $datei->move(dirname($ziel), basename($ziel));

        return $kandidat;
    }

    /** Der Einhaengepunkt — mit der Zusicherung, dass dort wirklich etwas haengt. */
    private function basis(Bereich $bereich): string
    {
        $quelle = $this->quelle($bereich);
        $basis = rtrim($quelle->mount_path, '/');

        if (! $quelle->is_active || $basis === '' || ! is_dir($basis)) {
            throw new AccessDeniedHttpException('Die externe Quelle ist nicht erreichbar.');
        }

        if (! $this->einhaengung->istEingehaengt($basis)) {
            throw new AccessDeniedHttpException('Die externe Quelle ist nicht eingehaengt.');
        }

        return $basis;
    }
}
