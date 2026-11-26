<?php

use Peppermint\Vault\Bereich;
use Peppermint\Vault\Contracts\Vaultrechte;
use Peppermint\Vault\Models\VaultSpace;

beforeEach(function () {
    $this->rechte = app(Vaultrechte::class);
});

it('gibt den persoenlichen Bereich nur der Person — auch keinem Verwalter', function () {
    $person = $this->person('Anna');
    $verwalter = $this->person('Chef', 'admin');

    expect($this->rechte->darfLesen($person, Bereich::person($person->id)))->toBeTrue()
        ->and($this->rechte->darfLesen($verwalter, Bereich::person($person->id)))->toBeFalse()
        ->and($this->rechte->darfSchreiben($verwalter, Bereich::person($person->id)))->toBeFalse();
});

it('laesst alle den globalen Bereich lesen, schreiben nur mit Recht', function () {
    $person = $this->person();
    $schreiber = $this->person('Schreiber');
    $schreiber->rechte = ['shared-vault.edit'];

    expect($this->rechte->darfLesen($person, Bereich::global()))->toBeTrue()
        ->and($this->rechte->darfSchreiben($person, Bereich::global()))->toBeFalse()
        ->and($this->rechte->darfSchreiben($schreiber, Bereich::global()))->toBeTrue();
});

it('folgt im Manager dem dort gesetzten Recht und Ordner', function () {
    config(['vault.bereiche.global' => 'firma', 'vault.rechte.global_schreiben' => 'vault.firma.bearbeiten']);
    $person = $this->person();
    $person->rechte = ['vault.firma.bearbeiten'];

    expect(Bereich::global()->scope())->toBe('area:firma')
        ->and($this->rechte->darfSchreiben($person, Bereich::aus('area:firma')))->toBeTrue();
});

it('gibt Raeume nur Mitgliedern, schreiben nur als editor', function () {
    $raum = VaultSpace::query()->create(['name' => 'Team', 'slug' => 'team']);
    $leser = $this->person('Leser');
    $editor = $this->person('Editor');
    $fremd = $this->person('Fremd');
    $raum->members()->attach($leser->id, ['role' => 'viewer']);
    $raum->members()->attach($editor->id, ['role' => 'editor']);

    expect($raum->canView($leser))->toBeTrue()
        ->and($raum->canEdit($leser))->toBeFalse()
        ->and($raum->canEdit($editor))->toBeTrue()
        ->and($raum->canView($fremd))->toBeFalse()
        ->and($raum->areaKey())->toBe('_spaces/team');
});

it('zeigt jeder Person ihren Bereich, den globalen und ihre Raeume', function () {
    VaultSpace::query()->create(['name' => 'Allgemein', 'slug' => 'allgemein', 'is_global' => true]);
    $team = VaultSpace::query()->create(['name' => 'Team', 'slug' => 'team']);
    VaultSpace::query()->create(['name' => 'Geheim', 'slug' => 'geheim']);
    $person = $this->person();
    $team->members()->attach($person->id);

    $scopes = array_map(fn (Bereich $b) => $b->scope(), $this->rechte->sichtbareBereiche($person));

    expect($scopes)->toBe(['user:'.$person->id, 'area:_shared', 'area:_spaces/team']);
});

it('laesst ein Produkt eigene Rechte binden', function () {
    app()->instance(Vaultrechte::class, new class implements Vaultrechte
    {
        public function istVerwalter($user): bool
        {
            return false;
        }

        public function darfLesen($user, Bereich $bereich): bool
        {
            return false;
        }

        public function darfSchreiben($user, Bereich $bereich): bool
        {
            return false;
        }

        public function sichtbareBereiche($user): array
        {
            return [];
        }
    });

    $raum = VaultSpace::query()->create(['name' => 'Allgemein', 'slug' => 'allgemein', 'is_global' => true]);

    expect($raum->canView($this->person()))->toBeFalse();
});
