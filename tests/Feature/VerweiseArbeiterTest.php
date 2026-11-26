<?php

use Illuminate\Cache\CacheManager;
use Illuminate\Support\Facades\Cache;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Papierkorb;
use Peppermint\Vault\Vault;
use Peppermint\Vault\Verweise;

/*
 * Octane: mehrere Arbeiter, je eigener Cache-Manager und eigene
 * Paket-Singletons. Ob sie einen Stand teilen, entscheidet allein der Store
 * (AI Brain: database). Nachgestellt mit dem file-Store; jeder Arbeiter
 * bekommt seinen eigenen CacheManager, und vor jedem Schritt wird auf den
 * Arbeiter umgeschaltet, der ihn tut.
 */
beforeEach(function () {
    config(['cache.default' => 'file', 'cache.stores.file.path' => sys_get_temp_dir().'/vault-arbeiter-'.uniqid()]);
    $this->bereich = Bereich::person(1);
});

/** @return array{cache: CacheManager, vault: Vault} */
function neuerArbeiter(): array
{
    $cache = new CacheManager(app());
    als(['cache' => $cache, 'vault' => null]);
    foreach ([Vault::class, Verweise::class, Papierkorb::class] as $klasse) {
        app()->forgetInstance($klasse);
    }

    return ['cache' => $cache, 'vault' => app(Vault::class)];
}

/** Auf einen Arbeiter umschalten: sein Cache-Manager gilt ab jetzt. */
function als(array $arbeiter): ?Vault
{
    app()->instance('cache', $arbeiter['cache']);
    app()->instance('cache.store', $arbeiter['cache']->driver());
    Cache::clearResolvedInstances();

    return $arbeiter['vault'];
}

function rueck(Vault $vault, Bereich $bereich): array
{
    return array_column($vault->verweise()->rueckverweise($bereich, 'b.md'), 'path');
}

it('zeigt auf Arbeiter A, was Arbeiter B geschrieben hat', function () {
    $a = neuerArbeiter();
    $b = neuerArbeiter();

    als($a)->schreiben($this->bereich, 'b.md', 'Ziel');
    als($a)->schreiben($this->bereich, 'a.md', '[[b]]');
    expect(rueck(als($a), $this->bereich))->toBe(['a.md']);   // A hat den Graph im Cache

    als($b)->schreiben($this->bereich, 'c.md', '[[b]]');

    expect(rueck(als($a), $this->bereich))->toBe(['a.md', 'c.md']);
});

it('zeigt auf Arbeiter A eine Notiz, die Arbeiter B aus dem Papierkorb geholt hat', function () {
    $a = neuerArbeiter();
    $b = neuerArbeiter();

    als($a)->schreiben($this->bereich, 'b.md', 'Ziel');
    als($a)->schreiben($this->bereich, 'a.md', '[[b]]');
    $eintrag = als($a)->loeschen($this->bereich, 'a.md');
    expect(rueck(als($a), $this->bereich))->toBe([]);          // im Cache: ohne a.md

    als($b)->papierkorb()->zurueckholen($eintrag);

    expect(rueck(als($a), $this->bereich))->toBe(['a.md']);
});
