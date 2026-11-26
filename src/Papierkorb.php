<?php

namespace Peppermint\Vault;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Peppermint\Vault\Events\DateiGeloescht;
use Peppermint\Vault\Events\DateiGespeichert;
use Peppermint\Vault\Models\VaultTrash;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Geloeschtes wird VERSCHOBEN, nicht entfernt (AI Brain #6223,
 * `VaultService:614–797`).
 */
class Papierkorb
{
    public function __construct(
        private readonly Ablage $ablage,
        private readonly Dateikennung $kennung,
    ) {}

    public function ablegen(Bereich $bereich, string $pfad, bool $istOrdner, ?int $geloeschtVon = null): VaultTrash
    {
        // Fehlt die Tabelle, wird NICHT still hart geloescht — das naehme genau
        // das Netz weg, das der Papierkorb ist.
        if (! Schema::hasTable(VaultTrash::tabelle())) {
            throw new AccessDeniedHttpException(
                'Der Papierkorb ist noch nicht eingerichtet — Loeschen ist bis dahin gesperrt, damit nichts ungesichert verschwindet.'
            );
        }

        $basis = $this->ablage->basis($bereich);
        $absolut = $this->ablage->aufloesen($bereich, $pfad);

        if (! file_exists($absolut)) {
            throw new NotFoundHttpException($istOrdner ? 'Folder not found.' : 'File not found.');
        }

        if ($istOrdner && realpath($absolut) === realpath($basis)) {
            throw new AccessDeniedHttpException('Die Wurzel laesst sich nicht loeschen.');
        }

        // Je Eintrag ein eigenes Verzeichnis: Zwei gleichnamige Dateien aus
        // verschiedenen Ordnern duerfen sich hier nicht ueberschreiben.
        $ablage = bin2hex(random_bytes(8)).'/'.basename($pfad);
        $ziel = $this->ablage->papierkorbPfad().'/'.$ablage;

        File::ensureDirectoryExists(dirname($ziel), 0775, true);
        File::move($absolut, $ziel);

        $eintrag = VaultTrash::query()->create([
            'scope' => $bereich->scope(),
            'original_path' => ltrim($pfad, '/'),
            'type' => $istOrdner ? 'folder' : 'file',
            'storage_path' => $ablage,
            'size' => $istOrdner ? null : (@filesize($ziel) ?: null),
            'deleted_by' => $geloeschtVon,
            'deleted_at' => now(),
        ]);

        $this->kennung->geloescht($bereich, $pfad, $eintrag);
        Event::dispatch(new DateiGeloescht($bereich, ltrim($pfad, '/'), $istOrdner, $eintrag->id));

        return $eintrag;
    }

    /** @return Collection<int, VaultTrash> */
    public function liste(Bereich $bereich, int $limit = 200): Collection
    {
        return VaultTrash::query()
            ->with('deletedBy:id,name')
            ->where('scope', $bereich->scope())
            ->orderByDesc('deleted_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Zurueck an den urspruenglichen Ort. Liegt dort inzwischen etwas, wird
     * ein freier Name gesucht — Wiederherstellen darf nie etwas anderes
     * vernichten.
     *
     * @return string Der Pfad, an dem es nun liegt
     */
    public function zurueckholen(VaultTrash $eintrag): string
    {
        $quelle = $eintrag->absoluterPfad();

        if (! file_exists($quelle)) {
            $eintrag->delete();

            throw new NotFoundHttpException('Der Inhalt im Papierkorb fehlt.');
        }

        $bereich = Bereich::aus($eintrag->scope);
        $ziel = $this->freierPfad($bereich, $eintrag->original_path);

        $absolut = $this->ablage->aufloesen($bereich, $ziel);
        File::ensureDirectoryExists(dirname($absolut), 0775, true);
        File::move($quelle, $absolut);

        $this->kennung->wiederhergestellt($eintrag, $ziel);
        $eintrag->delete();

        Event::dispatch(new DateiGespeichert($bereich, $ziel, $eintrag->istOrdner() ? null : $this->kennung->id($bereich, $ziel), 'restore'));

        return $ziel;
    }

    /** Endgueltig entfernen — Inhalt, Zeile, Datei-IDs. */
    public function leeren(VaultTrash $eintrag): void
    {
        $pfad = $eintrag->absoluterPfad();

        if ($eintrag->istOrdner()) {
            File::deleteDirectory($pfad);
        } else {
            File::delete($pfad);
        }

        $this->kennung->entfernt($eintrag);
        $eintrag->delete();
    }

    private function freierPfad(Bereich $bereich, string $pfad): string
    {
        if (! file_exists($this->ablage->aufloesen($bereich, $pfad))) {
            return $pfad;
        }

        $ordner = trim(dirname($pfad), '.');
        $ordner = $ordner === '' ? '' : rtrim($ordner, '/').'/';
        $name = basename($pfad);
        $endung = pathinfo($name, PATHINFO_EXTENSION);
        $stamm = pathinfo($name, PATHINFO_FILENAME);
        $anhang = $endung !== '' ? '.'.$endung : '';

        $i = 1;
        do {
            $kandidat = $i === 1
                ? "{$ordner}{$stamm}-wiederhergestellt{$anhang}"
                : "{$ordner}{$stamm}-wiederhergestellt-{$i}{$anhang}";
            $i++;
        } while (file_exists($this->ablage->aufloesen($bereich, $kandidat)));

        return $kandidat;
    }
}
