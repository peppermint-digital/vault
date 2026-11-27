<?php

namespace Peppermint\Vault;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Der Verweisgraph aus `[[wikilinks]]` (AI Brain `VaultService:1300–1640`).
 *
 * Je Bereich 24 Stunden zwischengespeichert, bei jedem Schreiben vergessen.
 * Der Schluessel des persoenlichen Bereichs ist der aus AI Brain
 * (`vault_graph_{id}`), damit ein Wechsel aufs Paket keinen kalten Cache
 * und keine zwei Wahrheiten erzeugt.
 */
class Verweise
{
    public function __construct(private readonly Ablage $ablage) {}

    /** @return array{nodes: array<array{id: string, label: string, path: string, linkCount: int}>, edges: array<array{source: string, target: string}>} */
    public function graph(Bereich $bereich): array
    {
        return Cache::remember($this->schluessel($bereich), now()->addMinutes(max(1, (int) config('vault.verweise.ttl_minuten', 1440))), fn () => $this->bauen($bereich));
    }

    /** @return array<array{id: string, label: string, path: string}> */
    public function rueckverweise(Bereich $bereich, string $ziel): array
    {
        $treffer = [];

        foreach ($this->graph($bereich)['edges'] as $kante) {
            if ($kante['target'] === $ziel) {
                $treffer[] = ['id' => $kante['source'], 'label' => pathinfo($kante['source'], PATHINFO_FILENAME), 'path' => $kante['source']];
            }
        }

        return $treffer;
    }

    /**
     * Oeffentlich fuer Produkte: nach einem Schreiben am Paket vorbei
     * (Agent mit eingehaengtem Vault, Sync) den Graph des Bereichs verwerfen.
     */
    public function vergessen(Bereich $bereich): void
    {
        Cache::forget($this->schluessel($bereich));
    }

    private function schluessel(Bereich $bereich): string
    {
        return $bereich->istPersoenlich()
            ? 'vault_graph_'.$bereich->schluessel
            : 'vault_graph_area_'.md5($bereich->schluessel);
    }

    /** @return array{nodes: array, edges: array} */
    private function bauen(Bereich $bereich): array
    {
        $basis = $this->ablage->basis($bereich);

        if (! File::isDirectory($basis)) {
            return ['nodes' => [], 'edges' => []];
        }

        $verborgen = $bereich->istPersoenlich() ? (array) config('vault.verborgene_wurzeldateien', []) : [];
        $dateien = [];
        foreach (File::allFiles($basis) as $datei) {
            if (in_array(strtolower($datei->getExtension()), Vault::INDIZIERT, true)) {
                $rel = $this->ablage->relativ($basis, $datei->getPathname());
                if (! in_array($rel, $verborgen, true)) {
                    $dateien[$rel] = $datei->getPathname();
                }
            }
        }

        $register = self::register(array_keys($dateien));

        $zaehler = array_fill_keys(array_keys($dateien), 0);
        $kanten = [];
        $gesehen = [];

        foreach ($dateien as $rel => $absolut) {
            // Obsidian maskiert `|` in Tabellenzellen als `\|`.
            $inhalt = str_replace('\|', '|', File::get($absolut));
            preg_match_all('/\[\[([^\]|#]+)(?:[|#][^\]]+)?\]\]/', $inhalt, $treffer);

            $eltern = dirname($rel);
            $praefix = ($eltern === '.' || $eltern === '') ? '' : $eltern.'/';

            foreach ($treffer[1] as $roh) {
                $ziel = self::aufloesen(trim($roh), $praefix, $register);

                if ($ziel !== null && $ziel !== $rel && ! isset($gesehen[$rel.'→'.$ziel])) {
                    $gesehen[$rel.'→'.$ziel] = true;
                    $kanten[] = ['source' => $rel, 'target' => $ziel];
                    $zaehler[$ziel] = ($zaehler[$ziel] ?? 0) + 1;
                }
            }
        }

        $knoten = array_map(function (string $rel) use ($zaehler): array {
            $name = pathinfo($rel, PATHINFO_FILENAME);
            $eltern = dirname($rel);

            // index.md ist eine Ordner-Uebersicht: der Ordnername ist die bessere Beschriftung.
            if ($name === 'index') {
                $name = $eltern === '.' ? 'index' : basename($eltern).'/';
            } elseif ($eltern !== '.') {
                $name = basename($eltern).'/'.$name;
            }

            return ['id' => $rel, 'label' => $name, 'path' => $rel, 'linkCount' => $zaehler[$rel] ?? 0];
        }, array_keys($dateien));

        return ['nodes' => array_values($knoten), 'edges' => $kanten];
    }

    /**
     * Wie Obsidian: zuerst ab Wurzel, dann relativ zur Quelldatei, dann
     * gleicher Ordner, zuletzt der Dateiname. md vor html.
     *
     * @param  array<string, string>  $register
     */
    /**
     * Namen -> Pfad, in der Reihenfolge der Pfade (spaetere gewinnen bei gleichem
     * Namen - wie bisher, weil File::allFiles nach Namen sortiert liefert).
     *
     * @param  list<string>  $pfade
     * @return array<string, string>
     */
    public static function register(array $pfade): array
    {
        $register = [];
        $muster = '/\.('.implode('|', Vault::INDIZIERT).')$/i';
        foreach ($pfade as $rel) {
            $ohne = pathinfo($rel, PATHINFO_FILENAME);
            $endung = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            $register[strtolower($rel)] = $rel;
            $register[strtolower(preg_replace($muster, '', $rel) ?? '')] = $rel;
            $register[strtolower($ohne)] = $rel;
            $register[strtolower($ohne.'.'.$endung)] = $rel;
        }

        return $register;
    }

    /**
     * Bequem fuer Tests und Produkte: Ziel eines [[Links]] aus der Datei $von.
     *
     * @param  list<string>  $pfade  alle indizierten Dateien des Bereichs
     */
    public static function zielFuer(array $pfade, string $von, string $link): ?string
    {
        sort($pfade, SORT_STRING);
        $eltern = dirname($von);
        $praefix = ($eltern === '.' || $eltern === '') ? '' : $eltern.'/';

        return self::aufloesen(trim($link), $praefix, self::register($pfade));
    }

    /**
     * Wohin ein [[Link]] zeigt - dieselbe Regel fuer Graph, „Verweise hierher" und
     * (seit 0.7.6) den Klick im Browser (resources/js/src/verweisZiel.ts ist der
     * wortgleiche Port; gemeinsame Faelle in tests/fixtures/verweis-ziele.json).
     *
     * @param  array<string, string>  $register
     */
    public static function aufloesen(string $link, string $praefix, array $register): ?string
    {
        $mitEndung = function (string $kandidat) use ($register): ?string {
            if (isset($register[$kandidat])) {
                return $register[$kandidat];
            }
            foreach (Vault::INDIZIERT as $endung) {
                $k = str_ends_with($kandidat, '.'.$endung) ? $kandidat : $kandidat.'.'.$endung;
                if (isset($register[$k])) {
                    return $register[$k];
                }
            }

            return null;
        };

        if (($ziel = $mitEndung(strtolower($link))) !== null) {
            return $ziel;
        }

        if ($praefix !== '' && str_contains($link, '/')) {
            $normal = self::normalisieren($praefix.$link);
            if ($normal !== null && ($ziel = $mitEndung(strtolower($normal))) !== null) {
                return $ziel;
            }
        } elseif ($praefix !== '' && ! str_starts_with($link, '..')) {
            foreach (Vault::INDIZIERT as $endung) {
                $k = strtolower($praefix.$link).'.'.$endung;
                if (isset($register[$k])) {
                    return $register[$k];
                }
            }
        }

        return $mitEndung(strtolower(basename($link)));
    }

    private static function normalisieren(string $pfad): ?string
    {
        $teile = [];
        foreach (explode('/', $pfad) as $segment) {
            if ($segment === '..') {
                if ($teile === []) {
                    return null;
                }
                array_pop($teile);
            } elseif ($segment !== '' && $segment !== '.') {
                $teile[] = $segment;
            }
        }

        return implode('/', $teile);
    }
}
