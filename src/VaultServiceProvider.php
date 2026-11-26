<?php

namespace Peppermint\Vault;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Peppermint\Vault\Console\PapierkorbLeerenCommand;
use Peppermint\Vault\Contracts\Benutzeradresse;
use Peppermint\Vault\Contracts\Vaultrechte;
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

        if (config('vault.routen.an', true)) {
            Route::prefix((string) config('vault.routen.praefix', 'vault/api'))
                ->middleware((array) config('vault.routen.middleware', ['web', 'auth']))
                ->name((string) config('vault.routen.name', 'vault.api.'))
                ->group(__DIR__.'/../routes/vault.php');

            Route::prefix((string) config('vault.routen.oeffentlich_praefix', 'vault/geteilt'))
                ->middleware((array) config('vault.routen.oeffentlich_middleware', ['web']))
                ->name((string) config('vault.routen.name', 'vault.api.').'geteilt.')
                ->group(__DIR__.'/../routes/oeffentlich.php');
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
    }
}
