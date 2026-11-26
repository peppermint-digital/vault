<?php

/*
 * 0.4.1: Der Document Server laeuft nur auf 127.0.0.1 und wird im Browser
 * unter <APP_URL>/eurooffice erreicht. Er teilt sich damit den Host mit dem
 * Produkt — der Schutz gegen fremde Datei-Adressen muss deshalb den Pfad
 * mitpruefen, und geholt wird ueber die interne Adresse.
 */

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\EuroOffice\Client;
use Peppermint\Vault\Vault;

beforeEach(function () {
    config()->set('vault.eurooffice', [
        'enabled' => true,
        'public_url' => 'https://manager.example.test/eurooffice',
        'internal_url' => 'http://127.0.0.1:8099',
        'jwt_secret' => str_repeat('s', 40),
        'jwt_header' => 'Authorization',
        'callback_base_url' => 'https://manager.example.test',
        'timeout_seconds' => 5,
        'praefix' => 'vault/eurooffice',
    ]);

    $this->client = app(Client::class);
});

it('erkennt nur Adressen unter dem Pfad des Document Servers als eigene an', function (string $url, bool $erwartet) {
    expect($this->client->isOwnDocumentServerUrl($url))->toBe($erwartet);
})->with([
    'Cache unter /eurooffice' => ['https://manager.example.test/eurooffice/cache/files/abc/output.xlsx', true],
    'interne Adresse' => ['http://127.0.0.1:8099/cache/files/abc/output.xlsx', true],
    'andere Seite des Produkts' => ['https://manager.example.test/vault/api/datei?path=x', false],
    'Wurzel des Produkts' => ['https://manager.example.test/', false],
    'Praefix nur als Wortanfang' => ['https://manager.example.test/euroofficeX/cache/x', false],
    'fremder Host' => ['http://169.254.169.254/latest/meta-data', false],
]);

it('holt die Datei ueber die interne Adresse statt ueber die eigene Domain', function () {
    expect($this->client->abrufAdresse('https://manager.example.test/eurooffice/cache/files/abc/output.xlsx'))
        ->toBe('http://127.0.0.1:8099/cache/files/abc/output.xlsx')
        ->and($this->client->abrufAdresse('http://127.0.0.1:8099/cache/x'))->toBe('http://127.0.0.1:8099/cache/x');

    config()->set('vault.eurooffice.internal_url', '');
    expect($this->client->abrufAdresse('https://manager.example.test/eurooffice/cache/x'))->toBe('https://manager.example.test/eurooffice/cache/x');
});

it('speichert ueber den Rueckweg mit Abruf auf 127.0.0.1 und lehnt andere Produkt-Adressen ab', function () {
    Http::fake(['*' => Http::response('NEU', 200)]);
    $vault = app(Vault::class);
    $person = $this->person('Anna');
    $bereich = Bereich::person($person->id);
    $quelle = tempnam(sys_get_temp_dir(), 'x');
    file_put_contents($quelle, 'alt');
    $vault->binaerSchreiben($bereich, 'tabelle.xlsx', $quelle);
    unlink($quelle);
    $ziel = $this->client->sign(['scope' => $bereich->scope(), 'path' => 'tabelle.xlsx', 'user' => (string) $person->id, 'iat' => time(), 'exp' => time() + 600]);
    $nutzlast = function (string $url): array {
        $rumpf = ['status' => 2, 'url' => $url, 'users' => ['anna']];
        $rumpf['token'] = $this->client->sign($rumpf);

        return $rumpf;
    };

    $this->postJson("/vault/eurooffice/rueckweg/{$ziel}", $nutzlast('https://manager.example.test/vault/api/datei?path=geheim'))
        ->assertJson(['error' => 1]);
    Http::assertNothingSent();

    $this->postJson("/vault/eurooffice/rueckweg/{$ziel}", $nutzlast('https://manager.example.test/eurooffice/cache/files/abc/output.xlsx'))
        ->assertJson(['error' => 0]);
    Http::assertSent(fn (Request $anfrage): bool => $anfrage->url() === 'http://127.0.0.1:8099/cache/files/abc/output.xlsx');
    expect(file_get_contents($vault->absolut($bereich, 'tabelle.xlsx')))->toBe('NEU');
});
