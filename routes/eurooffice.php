<?php

use Illuminate\Support\Facades\Route;
use Peppermint\Vault\Http\Controllers\EuroOfficeController;

/*
 * Wege OHNE Sitzung und ohne CSRF: Der Document Server hat beides nicht.
 * Gesichert sind sie ueber das eigene signierte Dokument-Token in der Adresse
 * (EuroOfficeController). Gedrosselt, damit niemand Tokens raten kann.
 */
Route::get('datei/{token}', [EuroOfficeController::class, 'datei'])->name('datei');
Route::post('rueckweg/{token}', [EuroOfficeController::class, 'rueckweg'])->name('rueckweg');
