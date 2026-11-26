<?php

namespace Peppermint\Vault\Console;

use Illuminate\Console\Command;
use Peppermint\Vault\Ablage;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Models\VaultTrash;
use Peppermint\Vault\Models\VaultVersion;
use Peppermint\Vault\Versionen;

/**
 * Zaehlt den Bestand aus der Zeit vor 0.4.0 (#10293): Versionen, zu denen es
 * weder eine Datei noch einen Papierkorb-Eintrag gibt, und leere Ordner im
 * Papierkorb.
 *
 * Bewusst NUR zaehlend. „Verwaist" ist am Bestand nicht sicher von „am Paket
 * vorbei verschoben" zu unterscheiden; geloescht wird erst nach einer
 * Entscheidung je Produkt. Die Ausgabe enthaelt nur Zahlen — keine Pfade,
 * keine Inhalte.
 */
class VersionenAufraeumenCommand extends Command
{
    protected $signature = 'vault:versionen-aufraeumen {--trocken : Nur zaehlen (derzeit der einzige Modus)}';

    protected $description = 'Zaehlt verwaiste Vault-Versionen und leere Papierkorb-Ordner (schreibt nichts)';

    public function handle(Ablage $ablage): int
    {
        if (! $this->option('trocken')) {
            $this->error('Nur mit --trocken: Dieser Befehl zaehlt, er loescht (noch) nichts.');

            return self::FAILURE;
        }

        $zahlen = [];
        $zaehle = function (string $art, string $was, int $n = 1) use (&$zahlen): void {
            $zahlen[$art][$was] = ($zahlen[$art][$was] ?? 0) + $n;
        };

        $eintraege = VaultTrash::query()->pluck('id')->flip();

        VaultVersion::query()
            ->selectRaw('scope, path, count(*) as anzahl')
            ->groupBy('scope', 'path')
            ->orderBy('scope')
            ->orderBy('path')
            ->cursor()
            ->each(function ($zeile) use ($ablage, $eintraege, $zaehle): void {
                $art = strstr((string) $zeile->scope, ':', true) ?: 'unbekannt';
                $anzahl = (int) $zeile->anzahl;

                if (str_starts_with((string) $zeile->path, Versionen::PAPIERKORB)) {
                    $id = (int) strtok(substr((string) $zeile->path, strlen(Versionen::PAPIERKORB)), '/');
                    $zaehle($art, $eintraege->has($id) ? 'im_papierkorb' : 'verwaist', $anzahl);

                    return;
                }

                try {
                    $bereich = Bereich::aus((string) $zeile->scope);
                    if ($bereich->istQuelle()) {
                        $zaehle($art, 'nicht_pruefbar', $anzahl);

                        return;
                    }
                    $da = file_exists($ablage->basis($bereich).DIRECTORY_SEPARATOR.$zeile->path);
                } catch (\Throwable) {
                    $zaehle($art, 'nicht_pruefbar', $anzahl);

                    return;
                }

                $zaehle($art, $da ? 'mit_datei' : 'verwaist', $anzahl);
            });

        $leer = 0;
        foreach (glob($ablage->papierkorbPfad().'/*', GLOB_ONLYDIR) ?: [] as $ordner) {
            if (count(scandir($ordner) ?: []) === 2) {
                $leer++;
            }
        }

        foreach ($zahlen as $art => $werte) {
            ksort($werte);
            $this->line($art.': '.collect($werte)->map(fn (int $n, string $k): string => "{$k}={$n}")->implode(' '));
        }
        $this->line("papierkorb_leere_ordner={$leer}");
        $this->info('Trocken: nichts geschrieben.');

        return self::SUCCESS;
    }
}
