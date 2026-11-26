<?php

use Illuminate\Support\Carbon;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Vault;

it('legt Fassung 1 mit Inhalt, Autor und Zeitpunkt der Quelle an, die Markdown-Fassung folgt', function () {
    $vault = app(Vault::class);
    $bereich = Bereich::geteilt('objekte/project/12');
    $damals = Carbon::parse('2026-03-04 10:11:12');

    $fassung1 = $vault->versionen()->uebernehmen($bereich, 'Notizen/plan (7).md', '<p>Ein <u>Plan</u></p>', 'anna@example.test', $damals);
    $vault->schreiben($bereich, 'Notizen/plan (7).md', "Ein **Plan**\n", 'umzug');

    $liste = $vault->versionen()->liste($bereich, 'Notizen/plan (7).md');

    expect($liste)->toHaveCount(2)
        ->and($liste->last()->id)->toBe($fassung1->id)
        ->and($fassung1->fresh()->created_at->equalTo($damals))->toBeTrue()
        ->and($fassung1->fresh()->author)->toBe('anna@example.test')
        ->and($fassung1->fresh()->content)->toBe('<p>Ein <u>Plan</u></p>')
        ->and($vault->lesen($bereich, 'Notizen/plan (7).md'))->toBe("Ein **Plan**\n");
});

it('wirft statt still zu scheitern', function () {
    app(Vault::class)->versionen()->uebernehmen(Bereich::person(1), '', 'x', null, now());
})->throws(InvalidArgumentException::class);
