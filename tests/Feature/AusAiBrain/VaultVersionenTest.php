<?php

/*
 * Aus AI Brain tests/Feature/VaultVersionsTest.php, VaultVersioningTest.php
 * und VaultBinaryVersionTest.php (Stand 118ee2b2).
 */

use Peppermint\Vault\Bereich;
use Peppermint\Vault\Models\VaultSpace;
use Peppermint\Vault\Models\VaultVersion;
use Peppermint\Vault\Vault;

beforeEach(function () {
    $this->vault = app(Vault::class);
    $this->bereich = Bereich::person(1);
    $this->quelle = tempnam(sys_get_temp_dir(), 'bin');
});

afterEach(fn () => @unlink($this->quelle));

it('versioniert keine nicht-editierbaren Endungen', function () {
    $this->vault->schreiben($this->bereich, 'daten.json', '{}');
    $this->vault->schreiben($this->bereich, 'liste.csv', 'a;b');

    expect(VaultVersion::query()->count())->toBe(0);
});

it('versioniert geteilte Bereiche unter ihrem eigenen Schluessel', function () {
    $this->vault->schreiben(Bereich::global(), 'n.md', 'x', 'assistant');

    expect(VaultVersion::query()->first())->scope->toBe('area:_shared')->author->toBe('assistant');
});

it('legt Binaerstaende auf die Platte, nur Metadaten in die Datenbank', function () {
    file_put_contents($this->quelle, 'xlsx-inhalt');
    $this->vault->binaerSchreiben($this->bereich, 'b.xlsx', $this->quelle);

    $v = VaultVersion::query()->first();

    expect($v->content)->toBe('')
        ->and($v->checksum)->toBe(hash('sha256', 'xlsx-inhalt'))
        ->and(file_get_contents($v->absoluteStoragePath()))->toBe('xlsx-inhalt');
});

it('legt keine zweite Binaerversion an, wenn sich nichts geaendert hat', function () {
    file_put_contents($this->quelle, 'gleich');
    $this->vault->binaerSchreiben($this->bereich, 'b.xlsx', $this->quelle);
    $this->vault->binaerSchreiben($this->bereich, 'b.xlsx', $this->quelle);

    expect(VaultVersion::query()->count())->toBe(1);
});

it('stellt einen Binaerstand byte-genau wieder her und legt dabei selbst eine Version an', function () {
    file_put_contents($this->quelle, "stand-1\x00");
    $this->vault->binaerSchreiben($this->bereich, 'b.xlsx', $this->quelle);
    $erste = VaultVersion::query()->first();
    file_put_contents($this->quelle, 'stand-2');
    $this->vault->binaerSchreiben($this->bereich, 'b.xlsx', $this->quelle);

    $this->vault->versionWiederherstellen($erste->id);

    expect(file_get_contents($this->vault->absolut($this->bereich, 'b.xlsx')))->toBe("stand-1\x00")
        ->and(VaultVersion::query()->count())->toBe(3);
});

it('meldet einen fehlenden Binaerstand deutlich, statt eine leere Datei zu schreiben', function () {
    file_put_contents($this->quelle, 'stand-1');
    $this->vault->binaerSchreiben($this->bereich, 'b.xlsx', $this->quelle);
    $v = VaultVersion::query()->first();
    unlink($v->absoluteStoragePath());

    expect(fn () => $this->vault->versionWiederherstellen($v->id))->toThrow(RuntimeException::class)
        ->and(file_get_contents($this->vault->absolut($this->bereich, 'b.xlsx')))->toBe('stand-1');
});

it('behaelt storage_path in der Versionsliste, sonst gilt jeder Stand als Text', function () {
    file_put_contents($this->quelle, 'x');
    $this->vault->binaerSchreiben($this->bereich, 'b.xlsx', $this->quelle);

    expect($this->vault->versionen()->liste($this->bereich, 'b.xlsx')->first()->isBinary())->toBeTrue();
});

it('wirft nicht, wenn die Datei fuer die Binaerversion fehlt', function () {
    $this->vault->versionen()->binaer($this->bereich, 'x.xlsx', '/gibt/es/nicht', null);

    expect(VaultVersion::query()->count())->toBe(0);
});

it('laesst einen Raum-Editor Versionen sehen und wiederherstellen, einen Leser nicht', function () {
    $raum = VaultSpace::query()->create(['name' => 'Team', 'slug' => 'team']);
    $editor = $this->person('Editor');
    $leser = $this->person('Leser');
    $raum->members()->attach($editor->id, ['role' => 'editor']);
    $raum->members()->attach($leser->id, ['role' => 'viewer']);
    $this->vault->schreiben(Bereich::raum('team'), 'n.md', 'eins');
    $this->vault->schreiben(Bereich::raum('team'), 'n.md', 'zwei');
    $erste = VaultVersion::query()->orderBy('id')->first();

    $this->actingAs($leser)->getJson('/vault/api/versionen?scope=team&path=n.md')->assertOk()->assertJsonCount(2, 'versions');
    $this->actingAs($leser)->postJson("/vault/api/versionen/{$erste->id}/wiederherstellen")->assertForbidden();
    $this->actingAs($editor)->postJson("/vault/api/versionen/{$erste->id}/wiederherstellen")->assertOk();

    expect($this->vault->lesen(Bereich::raum('team'), 'n.md'))->toBe('eins');
});
