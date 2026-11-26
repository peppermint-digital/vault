<?php

/*
 * Aus AI Brain tests/Feature/VaultServiceTest.php (Stand 118ee2b2), auf die
 * Paket-Schnittstelle uebertragen. Faelle, die AblageTest/VaultTest/
 * VerweiseTest schon tragen, stehen hier nicht doppelt.
 */

use Peppermint\Vault\Ablage;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Vault;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    $this->vault = app(Vault::class);
    $this->eins = Bereich::person(1);
});

it('legt das Verzeichnis eines Bereichs beim ersten Aufloesen an', function () {
    $basis = app(Ablage::class)->aufloesen(Bereich::person(42));

    expect(is_dir($basis))->toBeTrue();
});

it('liefert fuer einen leeren Bereich einen leeren Baum und baut sonst Ordner vor Dateien, sortiert', function () {
    expect($this->vault->baum(Bereich::person(77)))->toBe([]);

    $this->vault->schreiben($this->eins, 'b.md', 'x');
    $this->vault->schreiben($this->eins, 'a.md', 'x');
    $this->vault->schreiben($this->eins, 'z/c.md', 'x');

    $baum = $this->vault->baum($this->eins);

    expect(array_column($baum, 'path'))->toBe(['z', 'a.md', 'b.md'])
        ->and($baum[0]['children'][0]['path'])->toBe('z/c.md');
});

it('meldet eine fehlende Datei als 404', function () {
    $this->vault->lesen($this->eins, 'gibtsnicht.md');
})->throws(NotFoundHttpException::class);

it('laesst die Wurzel eines Bereichs nicht loeschen', function () {
    $this->vault->schreiben($this->eins, 'a.md', 'x');

    $this->vault->papierkorb()->ablegen($this->eins, '', true);
})->throws(AccessDeniedHttpException::class);

it('laesst Person 1 nicht ueber Pfade in den Bereich von Person 2', function () {
    $this->vault->schreiben(Bereich::person(2), 'geheim.md', 'x');

    $this->vault->lesen($this->eins, '../2/geheim.md');
})->throws(AccessDeniedHttpException::class);

it('findet HTML-Dateien neben Markdown und entfernt Tags aus dem Auszug', function () {
    $this->vault->schreiben($this->eins, 'bericht.html', '<h1>Quartal</h1><p>Umsatz <b>stieg</b> deutlich</p>');

    $treffer = $this->vault->suchen($this->eins, 'stieg');

    expect($treffer[0]['path'])->toBe('bericht.html')
        ->and($treffer[0]['excerpt'])->not->toContain('<b>');
});

it('beschriftet Knoten wie AI Brain', function () {
    $this->vault->schreiben($this->eins, 'oben.md', 'x');
    $this->vault->schreiben($this->eins, 'index.md', 'x');
    $this->vault->schreiben($this->eins, 'reisen/rom.md', 'x');

    $label = collect($this->vault->verweise()->graph($this->eins)['nodes'])->pluck('label', 'path');

    expect($label['oben.md'])->toBe('oben')
        ->and($label['index.md'])->toBe('index')
        ->and($label['reisen/rom.md'])->toBe('reisen/rom');
});

it('loest Wikilinks zwischen md und html in beide Richtungen auf, md vor html', function () {
    $this->vault->schreiben($this->eins, 'plan.md', 'siehe [[dash]] und [[seite.html]]');
    $this->vault->schreiben($this->eins, 'dash.html', '<p>[[plan]]</p>');
    $this->vault->schreiben($this->eins, 'seite.html', 'x');
    $this->vault->schreiben($this->eins, 'seite.md', 'x');
    $this->vault->schreiben($this->eins, 'doppel.md', 'x');
    $this->vault->schreiben($this->eins, 'doppel.html', 'x');
    $this->vault->schreiben($this->eins, 'zeiger.md', '[[doppel]]');

    $kanten = collect($this->vault->verweise()->graph($this->eins)['edges'])->map(fn ($k) => $k['source'].'→'.$k['target'])->all();

    expect($kanten)->toContain('plan.md→dash.html', 'plan.md→seite.html', 'dash.html→plan.md', 'zeiger.md→doppel.md')
        ->and(array_column($this->vault->verweise()->rueckverweise($this->eins, 'plan.md'), 'path'))->toBe(['dash.html']);
});
