<?php

use Peppermint\Vault\Ablage;
use Peppermint\Vault\Bereich;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

it('weist Ausbrueche aus dem Bereich ab', function (string $pfad) {
    app(Ablage::class)->aufloesen(Bereich::person(1), $pfad);
})->throws(AccessDeniedHttpException::class)->with([
    'Elternordner' => '../2/geheim.md',
    'tief' => 'a/../../2/x.md',
    'absolut' => '/etc/passwd',
    'Null-Byte' => "a\0.md",
]);

it('loest einen normalen Pfad im Bereich auf', function () {
    $pfad = app(Ablage::class)->aufloesen(Bereich::person(1), 'ordner/notiz.md');

    expect($pfad)->toEndWith('/vaults/1/ordner/notiz.md');
});

it('weist symbolische Links ab', function () {
    $ablage = app(Ablage::class);
    $basis = $ablage->basis(Bereich::person(1));
    mkdir($basis, 0755, true);
    file_put_contents($ablage->wurzel().'/draussen.md', 'x');
    symlink($ablage->wurzel().'/draussen.md', $basis.'/link.md');

    $ablage->aufloesen(Bereich::person(1), 'link.md');
})->throws(AccessDeniedHttpException::class);

it('haelt Bereich 1 und Bereich 10 auseinander, auch ueber einen verlinkten Ordner', function () {
    // In AI Brain lag /vaults/10/x scheinbar in /vaults/1 (Praefixvergleich ohne Trenner).
    $ablage = app(Ablage::class);
    $eins = $ablage->basis(Bereich::person(1));
    $zehn = $ablage->basis(Bereich::person(10));
    mkdir($eins, 0755, true);
    mkdir($zehn.'/privat', 0755, true);
    file_put_contents($zehn.'/privat/tagebuch.md', 'geheim');
    symlink($zehn.'/privat', $eins.'/abkuerzung');

    $ablage->aufloesen(Bereich::person(1), 'abkuerzung/tagebuch.md');
})->throws(AccessDeniedHttpException::class);

it('kennt die Bereichsschluessel aus AI Brain', function () {
    expect(Bereich::person(5)->scope())->toBe('user:5')
        ->and(Bereich::global()->scope())->toBe('area:_shared')
        ->and(Bereich::raum('team')->scope())->toBe('area:_spaces/team')
        ->and(Bereich::aus('area:_spaces/team')->istGleich(Bereich::raum('team')))->toBeTrue()
        ->and(Bereich::aus('user:7')->benutzerId())->toBe(7);
});

it('laesst keinen geteilten Bereich mit Ziffer oder Ausbruch zu', function (string $key) {
    Bereich::geteilt($key);
})->throws(InvalidArgumentException::class)->with(['1abc', '../x', 'a/../b', '']);
