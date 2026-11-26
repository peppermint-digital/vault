<?php

use Illuminate\Support\Facades\Schema;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Vault;

it('legt alle Tabellen unter MySQL an und arbeitet darauf', function () {
    foreach (['vault_spaces', 'vault_space_user', 'vault_versions', 'vault_trash', 'vault_shares', 'vault_files', 'vault_links'] as $tabelle) {
        expect(Schema::hasTable($tabelle))->toBeTrue();
    }

    $vault = app(Vault::class);
    $tief = str_repeat('ordner/', 60).'notiz.md';
    $vault->schreiben(Bereich::person(1), $tief, 'x');
    $vault->verschieben(Bereich::person(1), 'ordner', 'anders');

    expect($vault->kennung()->id(Bereich::person(1), 'anders/'.substr($tief, strlen('ordner/'))))->not->toBeNull();
});
