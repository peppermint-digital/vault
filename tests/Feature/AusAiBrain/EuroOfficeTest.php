<?php

/*
 * Aus AI Brain tests/Feature/EuroOfficeCallbackTest.php und
 * VaultOfficeDateiAnlegenTest.php (Stand 118ee2b2), dazu die neue Pruefung:
 * Der Rueckweg schreibt nur, solange die Person noch schreiben darf.
 */

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\EuroOffice\Blankovorlagen;
use Peppermint\Vault\EuroOffice\Client;
use Peppermint\Vault\Models\VaultSpace;
use Peppermint\Vault\Models\VaultVersion;
use Peppermint\Vault\Vault;

beforeEach(function () {
    config()->set('vault.eurooffice', [
        'enabled' => true,
        'public_url' => 'https://office.example.test/eurooffice',
        'internal_url' => '',
        'jwt_secret' => str_repeat('s', 40),
        'jwt_header' => 'Authorization',
        'callback_base_url' => 'https://manager.example.test',
        'timeout_seconds' => 5,
        'praefix' => 'vault/eurooffice',
    ]);

    $this->vault = app(Vault::class);
    $this->client = app(Client::class);
    $this->person = $this->person('Anna');
    $this->bereich = Bereich::person($this->person->id);

    $quelle = tempnam(sys_get_temp_dir(), 'x');
    file_put_contents($quelle, 'alter-inhalt');
    $this->vault->binaerSchreiben($this->bereich, 'tabelle.xlsx', $quelle);
    unlink($quelle);

    $this->ziel = rueckwegToken($this->bereich->scope(), 'tabelle.xlsx', (string) $this->person->id);
});

function rueckwegToken(string $scope, string $pfad, ?string $user): string
{
    $claims = ['scope' => $scope, 'path' => $pfad, 'iat' => time(), 'exp' => time() + 600];
    if ($user !== null) {
        $claims['user'] = $user;
    }

    return app(Client::class)->sign($claims);
}

function nutzlast(array $mehr = []): array
{
    $rumpf = array_merge(['status' => 2, 'url' => 'https://office.example.test/eurooffice/cache/files/abc/output.xlsx', 'users' => ['anna']], $mehr);
    $rumpf['token'] = app(Client::class)->sign($rumpf);

    return $rumpf;
}

it('schreibt die bearbeitete Datei und legt eine Binaerversion an — auch bei Status 6', function () {
    Http::fake(['*' => Http::sequence()->push('NEU', 200)->push('NEU-2', 200)]);

    $this->postJson("/vault/eurooffice/rueckweg/{$this->ziel}", nutzlast())->assertOk()->assertJson(['error' => 0]);
    expect(file_get_contents($this->vault->absolut($this->bereich, 'tabelle.xlsx')))->toBe('NEU');

    $this->postJson("/vault/eurooffice/rueckweg/{$this->ziel}", nutzlast(['status' => 6]))->assertJson(['error' => 0]);

    expect(VaultVersion::query()->where('path', 'tabelle.xlsx')->latest('id')->first()->author)->toBe('eurooffice:anna')
        ->and(VaultVersion::query()->count())->toBe(3);
});

it('schreibt nichts, wenn ohne Aenderung geschlossen wurde', function () {
    Http::fake();

    $this->postJson("/vault/eurooffice/rueckweg/{$this->ziel}", nutzlast(['status' => 4]))->assertJson(['error' => 0]);

    Http::assertNothingSent();
});

it('LEHNT verfaelschte, fremd signierte und unsignierte Anfragen ab', function () {
    Http::fake();
    $fremd = JWT::encode(['scope' => 'user:1', 'path' => 'x.xlsx', 'exp' => time() + 60], str_repeat('f', 40), 'HS256');
    $unsigniert = ['status' => 2, 'url' => 'https://office.example.test/eurooffice/x.xlsx'];

    $this->postJson('/vault/eurooffice/rueckweg/'.$this->ziel.'x', nutzlast())->assertJson(['error' => 1]);
    $this->postJson("/vault/eurooffice/rueckweg/{$fremd}", nutzlast())->assertJson(['error' => 1]);
    $this->postJson("/vault/eurooffice/rueckweg/{$this->ziel}", $unsigniert)->assertJson(['error' => 1]);

    Http::assertNothingSent();
    expect(file_get_contents($this->vault->absolut($this->bereich, 'tabelle.xlsx')))->toBe('alter-inhalt');
});

it('LEHNT eine Datei-Adresse ab, die nicht auf den eigenen Document Server zeigt', function () {
    Http::fake();

    $this->postJson("/vault/eurooffice/rueckweg/{$this->ziel}", nutzlast(['url' => 'http://169.254.169.254/latest/meta-data']))->assertJson(['error' => 1]);

    Http::assertNothingSent();
});

it('ueberschreibt nicht mit einer leeren oder fehlgeschlagenen Antwort', function () {
    Http::fake(['*' => Http::sequence()->push('', 200)->push('kaputt', 500)]);
    $this->postJson("/vault/eurooffice/rueckweg/{$this->ziel}", nutzlast())->assertJson(['error' => 1, 'message' => 'Leere Datei erhalten.']);

    $this->postJson("/vault/eurooffice/rueckweg/{$this->ziel}", nutzlast())->assertJson(['error' => 1, 'message' => 'Datei konnte nicht geladen werden.']);

    expect(file_get_contents($this->vault->absolut($this->bereich, 'tabelle.xlsx')))->toBe('alter-inhalt');
});

it('bestaetigt einen Speicherfehler des Servers, ohne zu schreiben', function () {
    Http::fake();

    $this->postJson("/vault/eurooffice/rueckweg/{$this->ziel}", nutzlast(['status' => 3]))->assertJson(['error' => 0]);

    Http::assertNothingSent();
});

it('lehnt ab, solange EuroOffice nicht konfiguriert ist', function () {
    config()->set('vault.eurooffice.jwt_secret', 'zu-kurz');

    $this->postJson("/vault/eurooffice/rueckweg/{$this->ziel}", ['status' => 2])->assertJson(['error' => 1]);
});

it('kann den Bereich ueber den Pfad im Token nicht verlassen', function () {
    Http::fake(['*' => Http::response('BOESE', 200)]);
    $boese = rueckwegToken($this->bereich->scope(), '../2/fremd.xlsx', (string) $this->person->id);

    $this->postJson("/vault/eurooffice/rueckweg/{$boese}", nutzlast())->assertJson(['error' => 1]);

    expect(file_exists(dirname($this->vault->absolut($this->bereich, '')).'/2/fremd.xlsx'))->toBeFalse();
});

it('schreibt nicht mehr, wenn die Person inzwischen keine Schreibrechte hat', function () {
    Http::fake(['*' => Http::response('NEU', 200)]);
    VaultSpace::query()->create(['name' => 'Team', 'slug' => 'team']);
    $raum = Bereich::raum('team');
    $quelle = tempnam(sys_get_temp_dir(), 'x');
    file_put_contents($quelle, 'team-alt');
    $this->vault->binaerSchreiben($raum, 'plan.xlsx', $quelle);
    unlink($quelle);

    $ohneRecht = rueckwegToken($raum->scope(), 'plan.xlsx', (string) $this->person->id);
    $ohnePerson = rueckwegToken($raum->scope(), 'plan.xlsx', null);

    $this->postJson("/vault/eurooffice/rueckweg/{$ohneRecht}", nutzlast())->assertJson(['error' => 1]);
    $this->postJson("/vault/eurooffice/rueckweg/{$ohnePerson}", nutzlast())->assertJson(['error' => 1]);

    expect(file_get_contents($this->vault->absolut($raum, 'plan.xlsx')))->toBe('team-alt');
});

it('baut eine signierte Konfiguration: Bearbeiten nur mit Recht, Rueckweg nur dann', function () {
    VaultSpace::query()->create(['name' => 'Allgemein', 'slug' => 'allgemein', 'is_global' => true]);
    $quelle = tempnam(sys_get_temp_dir(), 'x');
    file_put_contents($quelle, 'x');
    $this->vault->binaerSchreiben(Bereich::global(), 'liste.xlsx', $quelle);
    unlink($quelle);

    $eigen = $this->actingAs($this->person)->getJson('/vault/api/eurooffice/konfiguration?path=tabelle.xlsx')->assertOk()->json();
    $fremd = $this->actingAs($this->person)->getJson('/vault/api/eurooffice/konfiguration?scope=allgemein&path=liste.xlsx')->assertOk()->json();

    expect($eigen['config']['editorConfig']['mode'])->toBe('edit')
        ->and($eigen['config']['editorConfig']['callbackUrl'])->toStartWith('https://manager.example.test/vault/eurooffice/rueckweg/')
        ->and($this->client->verify($eigen['config']['token']))->not->toBeNull()
        ->and($fremd['config']['editorConfig']['mode'])->toBe('view')
        ->and($fremd['config']['editorConfig'])->not->toHaveKey('callbackUrl');
});

it('liefert die Datei nur gegen ein gueltiges Token', function () {
    $token = $this->client->issueDocumentToken($this->bereich->scope(), 'tabelle.xlsx', 10);

    expect($this->get("/vault/eurooffice/datei/{$token}")->assertOk()->streamedContent())->toBe('alter-inhalt');
    $this->get('/vault/eurooffice/datei/falsch')->assertForbidden();
});

it('hat fuer jedes angebotene Format eine Vorlage und legt Office-Dateien als Kopie an, nie ueber Vorhandenes', function () {
    foreach (Blankovorlagen::endungen() as $endung) {
        expect(Blankovorlagen::pfadFuer("x.{$endung}"))->not->toBeNull();
    }

    $this->actingAs($this->person)->postJson('/vault/api/datei', ['path' => 'neu.xlsx', 'type' => 'file'])->assertOk();
    $this->actingAs($this->person)->postJson('/vault/api/datei', ['path' => 'tabelle.xlsx', 'type' => 'file'])->assertStatus(422);
    $this->actingAs($this->person)->postJson('/vault/api/datei', ['path' => 'neu.ods', 'type' => 'file'])
        ->assertStatus(422)->assertJsonPath('error', fn ($t) => str_contains($t, '.xlsx'));

    expect(file_get_contents($this->vault->absolut($this->bereich, 'neu.xlsx')))->toBe(file_get_contents(Blankovorlagen::pfadFuer('x.xlsx')))
        ->and(VaultVersion::query()->where('path', 'neu.xlsx')->count())->toBe(1);
});

it('traegt keinen Namen in die Vorlagen', function () {
    foreach (Blankovorlagen::endungen() as $endung) {
        $zip = new ZipArchive;
        $zip->open(Blankovorlagen::pfadFuer("x.{$endung}"));
        $kern = (string) $zip->getFromName('docProps/core.xml');
        $zip->close();

        expect($kern)->not->toMatch('/<dc:creator>[^<]+<\/dc:creator>/');
    }
});
