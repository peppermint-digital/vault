<?php

use Illuminate\Support\Facades\Route;
use Peppermint\Vault\Http\Controllers\OeffentlicheFreigabeController;

/*
 * Oeffentliche Schiene der Freigabelinks — ohne Anmeldung, nur `web` fuer
 * die Sitzung (das entsperrte Passwort wird dort gemerkt). Die Seite selbst
 * gehoert dem Produkt.
 */
Route::get('{token}/info', [OeffentlicheFreigabeController::class, 'info'])->name('info');
Route::post('{token}/entsperren', [OeffentlicheFreigabeController::class, 'entsperren'])
    ->middleware('throttle:'.config('vault.routen.drossel_entsperren', 'vault-entsperren'))
    ->name('entsperren');
Route::get('{token}/roh/{unterpfad?}', [OeffentlicheFreigabeController::class, 'roh'])
    ->where('unterpfad', '.*')
    ->name('roh');
