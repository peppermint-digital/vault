<?php

use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Peppermint\Vault\VaultServiceProvider;

/*
 * EuroOffice-Routen getrennt schaltbar (0.3.5, AI Brain E5c): eigener Schalter,
 * Drossel und Konfigurations-Route konfigurierbar. Vorgaben = bisher.
 */

/** Routen neu aufbauen, wie sie der Provider mit dieser Konfiguration anlegt. */
function routenMit(array $konfiguration): RouteCollectionInterface
{
    foreach ($konfiguration as $schluessel => $wert) {
        config()->set($schluessel, $wert);
    }

    app('router')->setRoutes(new RouteCollection);
    (new VaultServiceProvider(app()))->boot();
    Route::getRoutes()->refreshNameLookups();

    return Route::getRoutes();
}

it('legt mit den Vorgaben alles an wie bisher', function () {
    $routen = routenMit([]);

    $konfiguration = $routen->getByName('vault.api.eurooffice.konfiguration');
    $datei = $routen->getByName('vault.api.eurooffice.datei');

    expect($konfiguration->uri())->toBe('vault/api/eurooffice/konfiguration')
        ->and($konfiguration->gatherMiddleware())->toBe(['web', 'auth'])
        ->and($datei->uri())->toBe('vault/eurooffice/datei/{token}')
        ->and($datei->gatherMiddleware())->toBe(['throttle:120,1'])
        ->and($routen->getByName('vault.api.eurooffice.rueckweg')->gatherMiddleware())->toBe(['throttle:120,1']);
});

it('haengt ohne eigenen Schalter an routen.an — aus heisst auch EuroOffice aus', function () {
    $routen = routenMit(['vault.routen.an' => false]);

    expect($routen->getByName('vault.api.eurooffice.datei'))->toBeNull()
        ->and($routen->getByName('vault.api.eurooffice.konfiguration'))->toBeNull()
        ->and($routen->getByName('vault.api.baum'))->toBeNull();
});

it('legt EuroOffice mit eigenem Schalter an, auch wenn die JSON-Schiene aus ist (AI Brain)', function () {
    $routen = routenMit([
        'vault.routen.an' => false,
        'vault.eurooffice.routen' => true,
        'vault.eurooffice.drossel' => 'throttle:eurooffice',
        'vault.eurooffice.konfiguration.praefix' => 'api/vault',
        'vault.eurooffice.konfiguration.middleware' => ['web', 'auth', 'modul:vault'],
    ]);

    $konfiguration = $routen->getByName('vault.api.eurooffice.konfiguration');

    expect($routen->getByName('vault.api.baum'))->toBeNull()
        ->and($konfiguration->uri())->toBe('api/vault/eurooffice/konfiguration')
        ->and($konfiguration->gatherMiddleware())->toBe(['web', 'auth', 'modul:vault'])
        ->and($routen->getByName('vault.api.eurooffice.datei')->gatherMiddleware())->toBe(['throttle:eurooffice']);
});

it('schaltet EuroOffice allein ab, die JSON-Schiene bleibt', function () {
    $routen = routenMit(['vault.eurooffice.routen' => false]);

    expect($routen->getByName('vault.api.baum'))->not->toBeNull()
        ->and($routen->getByName('vault.api.eurooffice.datei'))->toBeNull()
        ->and($routen->getByName('vault.api.eurooffice.konfiguration'))->toBeNull();
});

it('nimmt als Drossel auch eine Liste und laesst Leeres weg', function () {
    $routen = routenMit(['vault.eurooffice.drossel' => ['throttle:eurooffice', '', 'eurooffice.log']]);

    expect($routen->getByName('vault.api.eurooffice.rueckweg')->gatherMiddleware())->toBe(['throttle:eurooffice', 'eurooffice.log']);
});

/*
 * 0.6.1 (AI Brain F52, Riegel J2): Die oeffentliche Schiene ist eigens
 * schaltbar, und das Entsperren nutzt eine BENANNTE Drossel.
 */
it('legt die oeffentliche Schiene mit benannter Entsperr-Drossel an und meldet den Begrenzer an', function () {
    $routen = routenMit([]);
    $entsperren = $routen->getByName('vault.api.geteilt.entsperren');

    expect($entsperren->uri())->toBe('vault/geteilt/{token}/entsperren')
        ->and($entsperren->gatherMiddleware())->toContain('throttle:vault-entsperren')
        ->and(collect($entsperren->gatherMiddleware())->contains(fn ($m) => preg_match('/^throttle:\d+,\d+$/', $m)))->toBeFalse()
        ->and(RateLimiter::limiter('vault-entsperren'))->not->toBeNull();
});

it('nimmt den Namen der Drossel aus der Konfiguration', function () {
    expect(routenMit(['vault.routen.drossel_entsperren' => 'produkt-entsperren'])->getByName('vault.api.geteilt.entsperren')->gatherMiddleware())
        ->toContain('throttle:produkt-entsperren');
});

it('schaltet die oeffentliche Schiene allein ab, die JSON-Schiene bleibt', function () {
    $routen = routenMit(['vault.routen.oeffentlich_an' => false]);

    expect($routen->getByName('vault.api.geteilt.entsperren'))->toBeNull()
        ->and($routen->getByName('vault.api.geteilt.info'))->toBeNull()
        ->and($routen->getByName('vault.api.baum'))->not->toBeNull();
});
