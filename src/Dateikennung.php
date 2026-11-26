<?php

namespace Peppermint\Vault;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Peppermint\Vault\Models\VaultFile;
use Peppermint\Vault\Models\VaultTrash;

/**
 * Haelt `vault_files` mit der Platte im Gleichschritt (F42).
 *
 * Jeder Weg, der eine Datei anlegt, verschiebt, loescht oder zurueckholt,
 * meldet es hier. Die ID wandert mit dem Pfad; die Zeile einer geloeschten
 * Datei bleibt stehen, bis der Papierkorb endgueltig geleert wird.
 *
 * Fehler hier lassen das Schreiben NICHT scheitern — dasselbe Versprechen wie
 * bei den Versionen. Eine fehlende ID wird beim naechsten Zugriff ueber
 * {@see sicherstellen()} nachgetragen; eine verlorene Datei waere nicht
 * nachzutragen.
 */
class Dateikennung
{
    private ?bool $tabelleDa = null;

    public function __construct(private readonly Ablage $ablage) {}

    /** ID einer vorhandenen Datei; legt die Zeile an, wenn sie fehlt (Bestand). */
    public function sicherstellen(Bereich $bereich, string $pfad): ?string
    {
        if (! $this->aktiv()) {
            return null;
        }

        $pfad = $this->normal($pfad);
        $vorhanden = $this->zeile($bereich, $pfad);

        if ($vorhanden !== null) {
            return $vorhanden->id;
        }

        $absolut = $this->ablage->aufloesen($bereich, $pfad);

        return is_file($absolut) ? $this->gespeichert($bereich, $pfad, $absolut) : null;
    }

    public function id(Bereich $bereich, string $pfad): ?string
    {
        return $this->aktiv() ? $this->zeile($bereich, $this->normal($pfad))?->id : null;
    }

    public function gespeichert(Bereich $bereich, string $pfad, string $absolut): ?string
    {
        return $this->sicher(function () use ($bereich, $pfad, $absolut): ?string {
            $pfad = $this->normal($pfad);
            $zeile = $this->zeile($bereich, $pfad) ?? new VaultFile(['scope' => $bereich->scope(), 'path' => $pfad]);

            $zeile->fill([
                'checksum' => is_file($absolut) ? (hash_file('sha256', $absolut) ?: null) : null,
                'size' => is_file($absolut) ? (filesize($absolut) ?: 0) : null,
                'art' => strtolower(pathinfo($pfad, PATHINFO_EXTENSION)) ?: null,
            ])->save();

            return $zeile->id;
        });
    }

    public function verschoben(Bereich $bereich, string $von, string $nach): void
    {
        $this->sicher(function () use ($bereich, $von, $nach): void {
            $von = $this->normal($von);
            $nach = $this->normal($nach);

            foreach ($this->unter($bereich, $von) as $zeile) {
                $zeile->path = $zeile->path === $von ? $nach : $nach.substr($zeile->path, strlen($von));
                $zeile->save();
            }
        });
    }

    public function geloescht(Bereich $bereich, string $pfad, VaultTrash $eintrag): void
    {
        $this->sicher(function () use ($bereich, $pfad, $eintrag): void {
            foreach ($this->unter($bereich, $this->normal($pfad)) as $zeile) {
                $zeile->fill(['deleted_at' => now(), 'trash_id' => $eintrag->id])->save();
            }
        });
    }

    /** Zurueck aus dem Papierkorb — dieselben IDs, neuer Ort. */
    public function wiederhergestellt(VaultTrash $eintrag, string $ziel): void
    {
        $this->sicher(function () use ($eintrag, $ziel): void {
            $von = $this->normal($eintrag->original_path);
            $ziel = $this->normal($ziel);

            foreach (VaultFile::query()->where('trash_id', $eintrag->id)->get() as $zeile) {
                $zeile->fill([
                    'path' => $zeile->path === $von ? $ziel : $ziel.substr($zeile->path, strlen($von)),
                    'deleted_at' => null,
                    'trash_id' => null,
                ])->save();
            }
        });
    }

    /** Endgueltig weg: Zeilen samt Verknuepfungen entfernen. */
    public function entfernt(VaultTrash $eintrag): void
    {
        $this->sicher(function () use ($eintrag): void {
            VaultFile::query()->where('trash_id', $eintrag->id)->get()->each->delete();
        });
    }

    private function zeile(Bereich $bereich, string $pfad): ?VaultFile
    {
        return VaultFile::query()
            ->where('scope', $bereich->scope())
            ->where('path', $pfad)
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * Die Zeile des Pfads selbst und alle darunter (bei Ordnern).
     *
     * @return iterable<VaultFile>
     */
    private function unter(Bereich $bereich, string $pfad): iterable
    {
        // LIKE grob, str_starts_with genau: `_` und `%` sind in LIKE Platzhalter,
        // und SQLite kennt ohne ESCAPE-Klausel kein Ausweichzeichen.
        // `_attachments/%` traefe sonst auch `xattachments/…`.
        return VaultFile::query()
            ->where('scope', $bereich->scope())
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('path', $pfad)->orWhere('path', 'like', $pfad.'/%'))
            ->get()
            ->filter(fn (VaultFile $zeile): bool => $zeile->path === $pfad || str_starts_with($zeile->path, $pfad.'/'));
    }

    private function normal(string $pfad): string
    {
        return trim(str_replace('\\', '/', $pfad), '/');
    }

    private function aktiv(): bool
    {
        return $this->tabelleDa ??= Schema::hasTable(VaultFile::tabelle());
    }

    /**
     * @template T
     *
     * @param  callable(): T  $arbeit
     * @return T|null
     */
    private function sicher(callable $arbeit): mixed
    {
        if (! $this->aktiv()) {
            return null;
        }

        try {
            return $arbeit();
        } catch (\Throwable $e) {
            Log::warning('vault: Datei-ID nicht nachgefuehrt', ['fehler' => $e->getMessage()]);

            return null;
        }
    }
}
