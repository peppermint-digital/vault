<?php

use Peppermint\Vault\Verweise;

/*
 * 0.7.6 (#10335): Die Regel, nach der [[Links]] aufgeloest werden, ist fuer Graph,
 * „Verweise hierher" und den Klick im Browser dieselbe. Die erwarteten Ziele stehen
 * in tests/fixtures/verweis-ziele.json; resources/js/tests/VerweisZiel.test.ts prueft
 * den JS-Port gegen dieselbe Datei.
 */
$fixture = json_decode((string) file_get_contents(__DIR__.'/../fixtures/verweis-ziele.json'), true);
$indiziert = array_values(array_filter($fixture['dateien'], fn (string $p): bool => (bool) preg_match('/\.(md|html)$/i', $p)));

it('loest jeden Fall wie festgehalten auf', function (string $von, string $link, ?string $ziel) use ($indiziert) {
    expect(Verweise::zielFuer($indiziert, $von, $link))->toBe($ziel);
})->with(array_map(fn (array $f): array => [$f['von'], $f['link'], $f['ziel']], $fixture['faelle']));
