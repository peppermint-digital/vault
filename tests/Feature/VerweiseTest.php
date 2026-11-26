<?php

use Peppermint\Vault\Bereich;
use Peppermint\Vault\Vault;

it('baut den Verweisgraph aus Wikilinks und vergisst ihn beim Schreiben', function () {
    $vault = app(Vault::class);
    $bereich = Bereich::person(1);
    $vault->schreiben($bereich, 'themen/index.md', 'Start');
    $vault->schreiben($bereich, 'a.md', 'siehe [[b]] und [[themen/index|Themen]]');
    $vault->schreiben($bereich, 'b.md', 'zurueck zu [[a#Kopf]]');

    $graph = $vault->verweise()->graph($bereich);

    expect($graph['edges'])->toHaveCount(3)
        ->and(collect($graph['nodes'])->firstWhere('path', 'themen/index.md')['label'])->toBe('themen/')
        ->and(array_column($vault->verweise()->rueckverweise($bereich, 'b.md'), 'path'))->toBe(['a.md']);

    $vault->schreiben($bereich, 'c.md', '[[b]]');

    expect(array_column($vault->verweise()->rueckverweise($bereich, 'b.md'), 'path'))->toBe(['a.md', 'c.md']);
});
