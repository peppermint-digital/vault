<?php

use Illuminate\Support\Facades\Route;
use Peppermint\Vault\Http\Controllers\EuroOfficeController;

/*
 * Die Editor-Konfiguration fuer EuroOffice — MIT Sitzung (die Person muss die
 * Datei sehen duerfen). Eigene Datei, damit Praefix und Middleware getrennt von
 * der Haupt-Schiene setzbar sind (vault.eurooffice.konfiguration, 0.3.5).
 * Vorgabe: dieselben wie `vault.routen` — Adresse und Name wie bisher.
 */
Route::get('eurooffice/konfiguration', [EuroOfficeController::class, 'konfiguration'])->name('eurooffice.konfiguration');
