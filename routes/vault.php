<?php

use Illuminate\Support\Facades\Route;
use Peppermint\Vault\Http\Controllers\AnmerkungenController;
use Peppermint\Vault\Http\Controllers\DateienController;
use Peppermint\Vault\Http\Controllers\EuroOfficeController;
use Peppermint\Vault\Http\Controllers\FreigabenController;
use Peppermint\Vault\Http\Controllers\PapierkorbController;
use Peppermint\Vault\Http\Controllers\VersionenController;

/*
 * Die JSON-Schiene der Vault-Oberflaeche. Praefix, Middleware und
 * Namensvorsilbe kommen aus `vault.routen` (VaultServiceProvider).
 * Rechte prueft jede Aktion ueber `Vaultrechte` — die Middleware sorgt nur
 * fuer die Anmeldung.
 */
Route::get('baum', [DateienController::class, 'baum'])->name('baum');
Route::get('datei', [DateienController::class, 'zeigen'])->name('datei.zeigen');
Route::get('roh', [DateienController::class, 'roh'])->name('datei.roh');
Route::post('datei', [DateienController::class, 'anlegen'])->name('datei.anlegen');
Route::patch('datei', [DateienController::class, 'speichern'])->name('datei.speichern');
Route::delete('datei', [DateienController::class, 'loeschen'])->name('datei.loeschen');
Route::post('verschieben', [DateienController::class, 'verschieben'])->name('verschieben');
Route::post('hochladen', [DateienController::class, 'hochladen'])->name('hochladen');
Route::get('suche', [DateienController::class, 'suchen'])->name('suche');
Route::get('verweise', [DateienController::class, 'verweise'])->name('verweise');

Route::get('versionen', [VersionenController::class, 'liste'])->name('versionen');
Route::get('versionen/{version}', [VersionenController::class, 'inhalt'])->whereNumber('version')->name('versionen.inhalt');
Route::post('versionen/{version}/wiederherstellen', [VersionenController::class, 'wiederherstellen'])->whereNumber('version')->name('versionen.wiederherstellen');

Route::get('papierkorb', [PapierkorbController::class, 'liste'])->name('papierkorb');
Route::post('papierkorb/{eintrag}/wiederherstellen', [PapierkorbController::class, 'wiederherstellen'])->whereNumber('eintrag')->name('papierkorb.wiederherstellen');
Route::delete('papierkorb/{eintrag}', [PapierkorbController::class, 'entfernen'])->whereNumber('eintrag')->name('papierkorb.entfernen');

Route::get('freigaben', [FreigabenController::class, 'fuerPfad'])->name('freigaben');
Route::post('freigaben', [FreigabenController::class, 'anlegen'])->name('freigaben.anlegen');
Route::delete('freigaben/{freigabe}', [FreigabenController::class, 'zurueckziehen'])->whereNumber('freigabe')->name('freigaben.zurueckziehen');

Route::get('eurooffice/konfiguration', [EuroOfficeController::class, 'konfiguration'])->name('eurooffice.konfiguration');

Route::get('vorschau', [AnmerkungenController::class, 'vorschau'])->name('vorschau');
Route::get('anmerkungen', [AnmerkungenController::class, 'liste'])->name('anmerkungen');
Route::post('anmerkungen', [AnmerkungenController::class, 'anlegen'])->name('anmerkungen.anlegen');
Route::patch('anmerkungen', [AnmerkungenController::class, 'aendern'])->name('anmerkungen.aendern');
Route::delete('anmerkungen', [AnmerkungenController::class, 'entfernen'])->name('anmerkungen.entfernen');
