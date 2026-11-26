<?php

namespace Peppermint\Vault;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Peppermint\Vault\Bereichsnamen\StandardNamen;
use Peppermint\Vault\Console\PapierkorbLeerenCommand;
use Peppermint\Vault\Contracts\Benutzeradresse;
use Peppermint\Vault\Contracts\Bereichsnamen;
use Peppermint\Vault\Contracts\Einhaengung;
use Peppermint\Vault\Contracts\Vaultrechte;
use Peppermint\Vault\Events\DateiGeloescht;
use Peppermint\Vault\Events\DateiGespeichert;
use Peppermint\Vault\Events\DateiVerschoben;
use Peppermint\Vault\Quellen\Geraetepruefung;
use Peppermint\Vault\Quellen\Quellen;
use Peppermint\Vault\Rechte\EmailAdresse;
use Peppermint\Vault\Rechte\Standardrechte;
use Peppermint\Vault\Verknuepfung\Verknuepfungen;

class VaultServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/vault.php', 'vault');

        // Fehlt die Disk, eine lokale unter storage/app/vaults — nie eine fremde ueberschreiben.
        $disk = (string) config('vault.disk', 'vault');
        if (config("filesystems.disks.{$disk}") === null) {
            config(["filesystems.disks.{$disk}" => [
                'driver' => 'local',
                'root' => storage_path('app/vaults'),
                'throw' => true,
            ]]);
        }

        // `bindIf`: Ein Produkt mit eigener Rechte- oder Adresslogik behaelt sie.
        $this->app->bindIf(Vaultrechte::class, Standardrechte::class, true);
        $this->app->bindIf(Benutzeradresse::class, EmailAdresse::class, true);
        $this->app->bindIf(Einhaengung::class, Geraetepruefung::class, true);
        // Singleton: Produkte melden ihre Praefixe einmal im boot() an.
        $this->app->singleton(StandardNamen::class);
        $this->app->bindIf(Bereichsnamen::class, fn ($app) => $app->make(StandardNamen::class), true);
        $this->app->singleton(Quellen::class);

        $this->app->singleton(Ablage::class);
        $this->app->singleton(Dateikennung::class);
        $this->app->singleton(Versionen::class);
        $this->app->singleton(Papierkorb::class);
        $this->app->singleton(Verweise::class);
        $this->app->singleton(Vault::class);

        // Leer, bis das Produkt Regeln anmeldet: Welche Objekte es gibt, weiss nur das Produkt.
        $this->app->singleton(Ablageregeln::class);
        $this->app->singleton(Verknuepfungen::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/vault.php' => config_path('vault.php'),
        ], 'vault-config');

        if ($this->app->runningInConsole()) {
            $this->commands([PapierkorbLeerenCommand::class]);
        }

        /*
         * Der Verweisgraph liegt im geteilten Laravel-Cache (nicht im Speicher
         * eines Arbeiters). Vergessen wird an EINER Stelle, an jedem Datei-
         * Ereignis — auch auf Wegen, die selbst kein vergessen() rufen
         * (Papierkorb::zurueckholen lieferte den Graph sonst bis zu 24 h alt).
         */
        Event::listen(
            [DateiGespeichert::class, DateiVerschoben::class, DateiGeloescht::class],
            fn (DateiGespeichert|DateiVerschoben|DateiGeloescht $ereignis) => $this->app->make(Verweise::class)->vergessen($ereignis->bereich),
        );

        if (config('vault.routen.an', true)) {
            Route::prefix((string) config('vault.routen.praefix', 'vault/api'))
                ->middleware((array) config('vault.routen.middleware', ['web', 'auth']))
                ->name((string) config('vault.routen.name', 'vault.api.'))
                ->group(__DIR__.'/../routes/vault.php');

            if (config('vault.quellen', false)) {
                Route::prefix((string) config('vault.routen.praefix', 'vault/api'))
                    ->middleware((array) config('vault.routen.middleware', ['web', 'auth']))
                    ->name((string) config('vault.routen.name', 'vault.api.'))
                    ->group(__DIR__.'/../routes/quellen.php');
            }

            Route::prefix((string) config('vault.routen.oeffentlich_praefix', 'vault/geteilt'))
                ->middleware((array) config('vault.routen.oeffentlich_middleware', ['web']))
                ->name((string) config('vault.routen.name', 'vault.api.').'geteilt.')
                ->group(__DIR__.'/../routes/oeffentlich.php');
        }

        /*
         * EuroOffice mit eigenem Schalter (0.3.5, AI Brain E5c): Ein Produkt mit
         * eigener JSON-Schiene (routen.an = false) braucht trotzdem die Wege des
         * Document Servers. `null` = wie `vault.routen.an` (bisheriges Verhalten).
         */
        $euroOffice = config('vault.eurooffice.routen');
        if ($euroOffice === null ? (bool) config('vault.routen.an', true) : (bool) $euroOffice) {
            Route::prefix((string) (config('vault.eurooffice.konfiguration.praefix') ?? config('vault.routen.praefix', 'vault/api')))
                ->middleware((array) (config('vault.eurooffice.konfiguration.middleware') ?? config('vault.routen.middleware', ['web', 'auth'])))
                ->name((string) config('vault.routen.name', 'vault.api.'))
                ->group(__DIR__.'/../routes/eurooffice-konfiguration.php');

            Route::prefix((string) config('vault.eurooffice.praefix', 'vault/eurooffice'))
                ->middleware(self::drossel())
                ->name((string) config('vault.routen.name', 'vault.api.').'eurooffice.')
                ->group(__DIR__.'/../routes/eurooffice.php');
        }

        /*
         * Zwei Schalter, weil es zwei Arten Tabellen gibt:
         *
         * - `run_migrations`: die Tabellen, die AI Brain schon hat (Raeume,
         *   Versionen, Papierkorb, Freigaben). AI Brain stellt das auf false.
         * - `run_migrations_neu`: `vault_files` und `vault_links`, die es in
         *   keinem Produkt vorher gab. Hingen sie am ersten Schalter, entstuenden
         *   sie in AI Brain nie (Lehre mailbox #6119).
         *
         * Eigene Ordner, weil Laravel einen Migrationspfad nicht rekursiv liest.
         */
        if (config('vault.run_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations/bestand');
        }

        if (config('vault.run_migrations_neu', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations/neu');
        }

        if (config('vault.quellen', false) && config('vault.run_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations/quellen');
        }
    }

    /**
     * Die Drossel der Wege ohne Sitzung (Datei holen, Rueckweg). Ein Text oder
     * eine Liste von Middleware; Vorgabe wie bisher `throttle:120,1`. AI Brain
     * setzt eine benannte Drossel (Riegel gegen namenlose, #1428/#1457).
     *
     * @return list<string>
     */
    private static function drossel(): array
    {
        return array_values(array_filter(
            (array) config('vault.eurooffice.drossel', 'throttle:120,1'),
            fn ($eintrag): bool => is_string($eintrag) && $eintrag !== '',
        ));
    }
}
