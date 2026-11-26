<?php

/*
 * Aus AI Brain tests/Feature/VaultSpaceTest.php und VaultUnifiedTest.php
 * (Stand 118ee2b2). Raum anlegen/verwalten ist Sache des Produkts (AI Brain
 * SpaceController) und bleibt dort; hier steht, was das Paket traegt.
 */

use Peppermint\Vault\Bereich;
use Peppermint\Vault\Models\VaultSpace;
use Peppermint\Vault\Vault;

beforeEach(function () {
    $this->vault = app(Vault::class);
    VaultSpace::query()->create(['name' => 'Allgemein', 'slug' => 'allgemein', 'is_global' => true]);
    $this->raum = VaultSpace::query()->create(['name' => 'Team', 'slug' => 'team']);
});

it('haelt die Dateien eines Raums vom globalen Bereich getrennt', function () {
    $this->vault->schreiben(Bereich::raum('team'), 'n.md', 'team');
    $this->vault->schreiben(Bereich::global(), 'n.md', 'alle');

    expect($this->vault->lesen(Bereich::raum('team'), 'n.md'))->toBe('team')
        ->and($this->vault->lesen(Bereich::global(), 'n.md'))->toBe('alle');
});

it('laesst einen Editor im Raum schreiben', function () {
    $editor = $this->person('Editor');
    $this->raum->members()->attach($editor->id, ['role' => 'editor']);

    $this->actingAs($editor)->postJson('/vault/api/datei?scope=team', ['path' => 'n.md', 'type' => 'file', 'content' => 'x'])->assertOk();

    expect($this->vault->existiert(Bereich::raum('team'), 'n.md'))->toBeTrue();
});

it('ueberschreibt mit force trotz veralteter Fassung', function () {
    $person = $this->person();
    $this->vault->schreiben(Bereich::person($person->id), 'a.md', 'fremd');

    $this->actingAs($person)->patchJson('/vault/api/datei', ['path' => 'a.md', 'content' => 'meins', 'base_version' => 'alt', 'force' => true])->assertOk();

    expect($this->vault->lesen(Bereich::person($person->id), 'a.md'))->toBe('meins');
});

it('liefert Rohdateien und Suche im Raum, und privat unveraendert', function () {
    $editor = $this->person('Editor');
    $this->raum->members()->attach($editor->id, ['role' => 'viewer']);
    $this->vault->schreiben(Bereich::raum('team'), 'plan.md', 'Apfel im Team');
    $this->vault->schreiben(Bereich::person($editor->id), 'privat.md', 'Apfel privat');

    $this->actingAs($editor)->get('/vault/api/roh?scope=team&path=plan.md')->assertOk()->assertSee('Apfel im Team');
    $this->actingAs($editor)->getJson('/vault/api/suche?scope=team&q=apfel')->assertJsonCount(1)->assertJsonPath('0.path', 'plan.md');
    $this->actingAs($editor)->getJson('/vault/api/suche?q=apfel')->assertJsonCount(1)->assertJsonPath('0.path', 'privat.md');
});

it('haengt die Sicherheitsregel nur an HTML, nicht an jede Datei', function () {
    $person = $this->person();
    $this->vault->schreiben(Bereich::person($person->id), 'n.md', 'x');

    $antwort = $this->actingAs($person)->get('/vault/api/roh?path=n.md')->assertOk();

    expect($antwort->headers->get('Content-Security-Policy'))->toBeNull()
        ->and($antwort->headers->get('Content-Type'))->toStartWith('text/markdown');
});
