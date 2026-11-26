<?php

use Peppermint\Vault\Bereich;
use Peppermint\Vault\Models\VaultVersion;
use Peppermint\Vault\Vault;
use Peppermint\Vault\Versionen;

/*
 * #10293 gegen MySQL: Der Papierkorb-Pfad beginnt mit einem Nullbyte. Ob MySQL
 * es in VARCHAR speichert, per Gleichheit und per LIKE wiederfindet, faellt nur
 * dort auf — SQLite ist hier nachsichtiger.
 */

it('speichert und findet Papierkorb-Versionen mit Nullbyte unter MySQL', function () {
    $vault = app(Vault::class);
    $bereich = Bereich::person(1);
    $vault->schreiben($bereich, 'o_1/a.md', 'a');
    $vault->schreiben($bereich, 'o_1/b.md', 'b');
    $vault->schreiben($bereich, 'oX1/c.md', 'nachbar');

    $eintrag = $vault->loeschen($bereich, 'o_1');
    $praefix = Versionen::papierkorbPfad($eintrag->id, 'o_1');

    expect(VaultVersion::query()->where('path', $praefix.'/a.md')->count())->toBe(1)
        ->and(VaultVersion::query()->where('path', 'like', Versionen::PAPIERKORB.'%')->count())->toBe(2)
        ->and(VaultVersion::query()->where('path', 'oX1/c.md')->count())->toBe(1);

    $vault->papierkorb()->zurueckholen($eintrag);
    expect(VaultVersion::query()->where('path', 'o_1/a.md')->count())->toBe(1);

    $vault->papierkorb()->leeren($vault->loeschen($bereich, 'o_1'));
    expect(VaultVersion::query()->pluck('path')->all())->toBe(['oX1/c.md']);
});
