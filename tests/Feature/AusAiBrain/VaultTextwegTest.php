<?php

/*
 * Aus AI Brain tests/Feature/VaultTextWegSchreibtKeineBinaerdateiTest.php
 * und VaultVerborgeneWurzeldateienTest.php (Stand 118ee2b2).
 */

use Peppermint\Vault\Bereich;
use Peppermint\Vault\Models\VaultVersion;
use Peppermint\Vault\Vault;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

beforeEach(fn () => $this->vault = app(Vault::class));

it('nennt die Endung in der Absage und haelt auch Dateien ohne Endung ab', function () {
    expect(fn () => $this->vault->schreiben(Bereich::person(1), 'tabelle.ods', 'x'))->toThrow(UnprocessableEntityHttpException::class, '.ods')
        ->and(fn () => $this->vault->schreiben(Bereich::person(1), 'Makefile', 'x'))->toThrow(UnprocessableEntityHttpException::class, 'ohne Endung');
});

it('laesst die vertrauten Textformate durch, auch im geteilten Bereich', function () {
    foreach (['md', 'markdown', 'html', 'htm', 'txt', 'excalidraw', 'json', 'csv'] as $endung) {
        $this->vault->schreiben(Bereich::global(), "datei.{$endung}", 'x');
    }

    expect(count($this->vault->baum(Bereich::global())))->toBe(8);
});

it('legt fuer eine abgelehnte Datei weder Datei noch Version an', function () {
    try {
        $this->vault->schreiben(Bereich::global(), 'tabelle.xlsx', 'x');
    } catch (UnprocessableEntityHttpException) {
    }

    expect($this->vault->existiert(Bereich::global(), 'tabelle.xlsx'))->toBeFalse()
        ->and(VaultVersion::query()->count())->toBe(0);
});

it('antwortet am Schreib-Endpunkt mit 422 und lesbarer Begruendung', function () {
    $person = $this->person();
    $quelle = tempnam(sys_get_temp_dir(), 'bin');
    file_put_contents($quelle, 'echt');
    $this->vault->binaerSchreiben(Bereich::person($person->id), 'tabelle.ods', $quelle);
    unlink($quelle);

    $this->actingAs($person)->patchJson('/vault/api/datei', ['path' => 'tabelle.ods', 'content' => ''])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, '.ods'));

    expect(file_get_contents($this->vault->absolut(Bereich::person($person->id), 'tabelle.ods')))->toBe('echt');
});

it('verbirgt nur die Wurzeldatei, laesst sie aber auf der Platte und anderswo sichtbar', function () {
    config(['vault.verborgene_wurzeldateien' => ['CLAUDE.md']]);
    $b = Bereich::person(1);
    $this->vault->schreiben($b, 'CLAUDE.md', 'Persona');
    $this->vault->schreiben($b, 'README.md', 'Hallo');
    $this->vault->schreiben($b, 'projekt/CLAUDE.md', 'eigene Notiz [[README]]');

    $pfade = collect($this->vault->baum($b))->flatMap(fn ($k) => [$k['path'], ...array_column($k['children'] ?? [], 'path')])->all();
    $knoten = array_column($this->vault->verweise()->graph($b)['nodes'], 'path');

    expect($pfade)->not->toContain('CLAUDE.md')->toContain('README.md', 'projekt/CLAUDE.md')
        ->and($knoten)->not->toContain('CLAUDE.md')
        ->and($this->vault->lesen($b, 'CLAUDE.md'))->toBe('Persona');
});
