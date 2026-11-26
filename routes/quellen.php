<?php

use Illuminate\Support\Facades\Route;
use Peppermint\Vault\Http\Controllers\QuellenController;

/* Externe Quellen (`?scope=extern:<slug>`), nur mit `vault.quellen`. */
Route::get('quelle/ebene', [QuellenController::class, 'ebene'])->name('quelle.ebene');
Route::get('quelle/datei', [QuellenController::class, 'zeigen'])->name('quelle.datei.zeigen');
Route::get('quelle/roh', [QuellenController::class, 'roh'])->name('quelle.datei.roh');
Route::post('quelle/datei', [QuellenController::class, 'anlegen'])->name('quelle.datei.anlegen');
Route::patch('quelle/datei', [QuellenController::class, 'speichern'])->name('quelle.datei.speichern');
Route::delete('quelle/datei', [QuellenController::class, 'loeschen'])->name('quelle.datei.loeschen');
Route::post('quelle/verschieben', [QuellenController::class, 'verschieben'])->name('quelle.verschieben');
Route::post('quelle/hochladen', [QuellenController::class, 'hochladen'])->name('quelle.hochladen');
