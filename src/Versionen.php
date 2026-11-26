<?php

namespace Peppermint\Vault;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Peppermint\Vault\Models\VaultVersion;

/**
 * Versionen von Text- und Binaerdateien (AI Brain #2905, #6219).
 *
 * Aus `VaultService:1001–1228` uebernommen. Beide Wege werfen NICHT: Eine
 * fehlgeschlagene Version darf das Speichern der Datei nie verhindern.
 */
class Versionen
{
    /** Endungen, die als Notiz versioniert werden (Text-Weg). */
    public const EDITIERBAR = ['md', 'html', 'excalidraw'];

    public function __construct(private readonly Ablage $ablage) {}

    /** Dedupliziert gleichen Inhalt und beschneidet auf die Hoechstzahl je Datei. */
    public function text(Bereich $bereich, string $pfad, string $inhalt, ?string $autor): void
    {
        try {
            if (! in_array(strtolower(pathinfo($pfad, PATHINFO_EXTENSION)), self::EDITIERBAR, true)) {
                return;
            }

            $letzte = $this->letzte($bereich, $pfad);
            if ($letzte !== null && $letzte->content === $inhalt) {
                return;
            }

            VaultVersion::query()->create([
                'scope' => $bereich->scope(),
                'path' => $pfad,
                'content' => $inhalt,
                'author' => $autor,
                'size' => strlen($inhalt),
                'created_at' => now(),
            ]);

            $this->beschneiden($bereich, $pfad, VaultVersion::maxPerFile());
        } catch (\Throwable $e) {
            Log::warning('vault version snapshot failed', ['scope' => $bereich->scope(), 'path' => $pfad, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Binaerversion: Inhalt auf die Platte unter dem Versionspfad, Vergleich
     * ueber die Pruefsumme (eine 20-MB-Tabelle bei jedem Speichern einzulesen
     * bremste den Editor).
     */
    public function binaer(Bereich $bereich, string $pfad, string $absolut, ?string $autor): void
    {
        try {
            if (! is_file($absolut)) {
                return;
            }

            $pruefsumme = hash_file('sha256', $absolut);
            if ($pruefsumme === false) {
                return;
            }

            $letzte = $this->letzte($bereich, $pfad);
            if ($letzte !== null && $letzte->checksum === $pruefsumme) {
                return;
            }

            $ablage = $this->blobAblegen($bereich, $pfad, $absolut);
            if ($ablage === null) {
                return;
            }

            VaultVersion::query()->create([
                'scope' => $bereich->scope(),
                'path' => $pfad,
                'content' => '',
                'storage_path' => $ablage,
                'checksum' => $pruefsumme,
                'author' => $autor,
                'size' => (int) (filesize($absolut) ?: 0),
                'created_at' => now(),
            ]);

            $this->beschneiden($bereich, $pfad, VaultVersion::maxBinaryPerFile());
        } catch (\Throwable $e) {
            Log::warning('vault binary version snapshot failed', ['scope' => $bereich->scope(), 'path' => $pfad, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Eine Fassung mit vorgegebenem Inhalt, Autor und Zeitpunkt uebernehmen —
     * fuer Umzuege (V3c: Fassung 1 = Original-HTML der alten Notiz, mit dem
     * Autor und dem Zeitpunkt der Quelle).
     *
     * Anders als {@see text()} WIRFT dieser Weg: Ein Umzug muss wissen, ob die
     * Fassung angelegt ist, sonst waere „verlustfrei" nicht pruefbar. Kein
     * Abgleich auf gleichen Inhalt und keine Endungspruefung — uebernommen
     * wird, was die Quelle hatte. Dieser Aufruf beschneidet nicht.
     *
     * GRENZE: Spaetere Schreibvorgaenge beschneiden wie immer auf die neuesten
     * `versionen.max_je_datei` (Vorgabe 30). Nach so vielen Bearbeitungen faellt
     * auch die uebernommene Fassung weg. Ob sie dauerhaft geschuetzt sein muss,
     * ist offen (Frage an Bastian, Meldung 0.3.0).
     */
    public function uebernehmen(Bereich $bereich, string $pfad, string $inhalt, ?string $autor, \DateTimeInterface $zeitpunkt): VaultVersion
    {
        $pfad = trim(str_replace('\\', '/', $pfad), '/');

        if ($pfad === '') {
            throw new \InvalidArgumentException('Eine Fassung braucht einen Pfad.');
        }

        return VaultVersion::query()->create([
            'scope' => $bereich->scope(),
            'path' => $pfad,
            'content' => $inhalt,
            'author' => $autor !== null ? mb_substr($autor, 0, 128) : null,
            'size' => strlen($inhalt),
            'created_at' => $zeitpunkt,
        ]);
    }

    /**
     * Neueste zuerst, OHNE Inhalt. `storage_path` muss mit, sonst haelt die
     * Oberflaeche jeden Binaerstand fuer Text.
     *
     * @return Collection<int, VaultVersion>
     */
    public function liste(Bereich $bereich, string $pfad): Collection
    {
        return VaultVersion::query()
            ->select(['id', 'scope', 'path', 'storage_path', 'checksum', 'author', 'size', 'created_at'])
            ->where('scope', $bereich->scope())
            ->where('path', $pfad)
            ->orderByDesc('id')
            ->get();
    }

    public function finden(int $id): ?VaultVersion
    {
        return VaultVersion::query()->find($id);
    }

    /** Versionen wandern beim Verschieben mit — sonst stuende die Historie am alten Pfad. */
    public function verschoben(Bereich $bereich, string $von, string $nach): void
    {
        try {
            VaultVersion::query()
                ->where('scope', $bereich->scope())
                ->where(fn ($q) => $q->where('path', $von)->orWhere('path', 'like', $von.'/%'))
                ->get()
                ->filter(fn (VaultVersion $v): bool => $v->path === $von || str_starts_with($v->path, $von.'/'))
                ->each(function (VaultVersion $v) use ($von, $nach): void {
                    $v->path = $v->path === $von ? $nach : $nach.substr($v->path, strlen($von));
                    $v->save();
                });
        } catch (\Throwable $e) {
            Log::warning('vault: Versionen nicht mitverschoben', ['error' => $e->getMessage()]);
        }
    }

    private function letzte(Bereich $bereich, string $pfad): ?VaultVersion
    {
        return VaultVersion::query()
            ->where('scope', $bereich->scope())
            ->where('path', $pfad)
            ->orderByDesc('id')
            ->first();
    }

    /** Der Name traegt Zufall, damit zwei Speichervorgaenge derselben Sekunde sich nicht ueberschreiben. */
    private function blobAblegen(Bereich $bereich, string $pfad, string $absolut): ?string
    {
        $basis = $this->ablage->versionsPfad();
        if ($basis === '') {
            return null;
        }

        $fach = preg_replace('/[^A-Za-z0-9_-]+/', '_', $bereich->scope()).'/'.substr(hash('sha256', $pfad), 0, 16);
        $endung = strtolower(pathinfo($pfad, PATHINFO_EXTENSION));
        $name = now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).($endung !== '' ? '.'.$endung : '');

        File::ensureDirectoryExists($basis.'/'.$fach, 0775, true);

        if (! @copy($absolut, $basis.'/'.$fach.'/'.$name)) {
            return null;
        }

        return $fach.'/'.$name;
    }

    /** Loescht auch die abgelegten Dateien — sonst liefe die Platte still voll. */
    private function beschneiden(Bereich $bereich, string $pfad, int $behalten): void
    {
        $behaltenIds = VaultVersion::query()
            ->where('scope', $bereich->scope())
            ->where('path', $pfad)
            ->orderByDesc('id')
            ->limit(max(1, $behalten))
            ->pluck('id');

        $weg = VaultVersion::query()
            ->where('scope', $bereich->scope())
            ->where('path', $pfad)
            ->whereNotIn('id', $behaltenIds)
            ->get();

        foreach ($weg as $version) {
            $blob = $version->absoluteStoragePath();
            if ($blob !== null && is_file($blob)) {
                @unlink($blob);
            }
            $version->delete();
        }
    }
}
