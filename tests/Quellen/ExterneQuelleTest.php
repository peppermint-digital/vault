<?php

/*
 * Aus AI Brain tests/Feature/VaultExterneQuelleTest.php (Stand 118ee2b2).
 *
 * „Eingehaengt" wird hergestellt, nicht behauptet: Der Einhaengepunkt ist ein
 * Verweis auf ein Verzeichnis im tmpfs, sein Elternverzeichnis liegt auf der
 * Platte — genau diese Geraete-Trennung prueft der Code. Quellen anlegen und
 * Mitglieder verwalten ist Sache des Produkts und bleibt in AI Brain.
 */

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Peppermint\Vault\Ablage;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Contracts\Vaultrechte;
use Peppermint\Vault\EuroOffice\Client;
use Peppermint\Vault\Models\VaultSource;
use Peppermint\Vault\Models\VaultVersion;
use Peppermint\Vault\Navigation;
use Peppermint\Vault\Quellen\Quellen;
use Peppermint\Vault\Vault;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

beforeEach(function () {
    $this->aufPlatte = storage_path('framework/testing/quellen-'.bin2hex(random_bytes(5)));
    File::ensureDirectoryExists($this->aufPlatte, 0775, true);

    $this->imSpeicher = sys_get_temp_dir().'/vault-quelle-'.bin2hex(random_bytes(5));
    File::ensureDirectoryExists($this->imSpeicher.'/fotos/2026', 0775, true);
    file_put_contents($this->imSpeicher.'/liste.md', 'Inhalt der Freigabe');
    file_put_contents($this->imSpeicher.'/fotos/2026/bild.jpg', 'jpg');
    $this->eingehaengt = $this->aufPlatte.'/eingehaengt';
    symlink($this->imSpeicher, $this->eingehaengt);

    $this->nichtEingehaengt = $this->aufPlatte.'/nicht-eingehaengt';
    File::ensureDirectoryExists($this->nichtEingehaengt, 0775, true);

    $this->quelle = VaultSource::query()->create(['name' => 'Grafik', 'slug' => 'grafik', 'mount_path' => $this->eingehaengt]);
    $this->weg = VaultSource::query()->create(['name' => 'Weg', 'slug' => 'weg', 'mount_path' => $this->nichtEingehaengt]);
    $this->editor = $this->person('Editor');
    $this->leser = $this->person('Leser');
    foreach ([$this->quelle, $this->weg] as $q) {
        $q->members()->attach($this->editor->id, ['role' => 'editor']);
        $q->members()->attach($this->leser->id, ['role' => 'viewer']);
    }
    $this->quellen = app(Quellen::class);
});

afterEach(function () {
    File::deleteDirectory($this->aufPlatte);
    File::deleteDirectory($this->imSpeicher);
});

it('erkennt eine eingehaengte Quelle an der Geraete-Kennung', function () {
    expect($this->quelle->istEingehaengt())->toBeTrue()
        ->and($this->weg->istEingehaengt())->toBeFalse();
});

it('verbietet das Schreiben, solange die Quelle nicht eingehaengt ist, und legt dort nichts an', function () {
    $rechte = app(Vaultrechte::class);

    expect($rechte->darfSchreiben($this->editor, Bereich::quelle('weg')))->toBeFalse()
        ->and(fn () => $this->quellen->schreiben(Bereich::quelle('weg'), 'neu.md', 'x'))->toThrow(AccessDeniedHttpException::class)
        ->and(File::files($this->nichtEingehaengt))->toBe([]);
});

it('weist auch das Lesen ab, wenn die Einhaengung mitten im Lauf wegbricht', function () {
    expect($this->quellen->lesen(Bereich::quelle('grafik'), 'liste.md'))->toBe('Inhalt der Freigabe');

    $this->quelle->forceFill(['mount_path' => $this->nichtEingehaengt])->save();

    expect(fn () => $this->quellen->lesen(Bereich::quelle('grafik'), 'liste.md'))->toThrow(AccessDeniedHttpException::class);
});

it('zeigt die Quelle nur Eingetragenen — auch Verwaltern nicht automatisch', function () {
    $verwalter = $this->person('Chef', 'admin');
    $rechte = app(Vaultrechte::class);

    expect($rechte->darfLesen($verwalter, Bereich::quelle('grafik')))->toBeFalse()
        ->and($rechte->darfLesen($this->leser, Bereich::quelle('grafik')))->toBeTrue()
        ->and($rechte->darfSchreiben($this->leser, Bereich::quelle('grafik')))->toBeFalse()
        ->and(array_map(fn ($b) => $b->scope(), $rechte->sichtbareBereiche($this->leser)))->toContain('source:grafik');
});

it('liefert nur EINE Ebene, nicht den ganzen Baum', function () {
    $ebene = $this->quellen->ebene(Bereich::quelle('grafik'));
    $tiefer = $this->quellen->ebene(Bereich::quelle('grafik'), 'fotos');

    expect(array_column($ebene, 'path'))->toBe(['fotos', 'liste.md'])
        ->and(array_column($tiefer, 'path'))->toBe(['fotos/2026']);
});

it('schreibt ohne Version, und die normale Ablage verweigert Quellen', function () {
    $this->quellen->schreiben(Bereich::quelle('grafik'), 'notiz.md', 'x');

    expect(file_get_contents($this->imSpeicher.'/notiz.md'))->toBe('x')
        ->and(VaultVersion::query()->count())->toBe(0)
        ->and(fn () => app(Ablage::class)->basis(Bereich::quelle('grafik')))->toThrow(LogicException::class);
});

it('laesst keinen Ausbruch aus der Quelle zu', function () {
    expect(fn () => $this->quellen->lesen(Bereich::quelle('grafik'), '../nicht-eingehaengt/x'))->toThrow(AccessDeniedHttpException::class);
});

it('sagt ueber die Schiene „nicht erreichbar" statt „leer", wenn die Quelle weg ist', function () {
    $this->actingAs($this->editor)->getJson('/vault/api/quelle/ebene?scope=extern:weg')
        ->assertOk()->assertJsonPath('erreichbar', false);

    $this->actingAs($this->editor)->getJson('/vault/api/quelle/ebene?scope=extern:grafik&path=fotos')
        ->assertOk()->assertJsonPath('erreichbar', true)->assertJsonPath('eintraege.0.path', 'fotos/2026');
});

it('gibt einer fremden Person die Quelle gar nicht erst', function () {
    $fremd = $this->person('Fremd');

    $this->actingAs($fremd)->getJson('/vault/api/quelle/ebene?scope=extern:grafik')->assertNotFound();
});

it('laesst einen Leser lesen, aber nicht hochladen; ein Editor laedt hoch ohne zu ueberschreiben', function () {
    $this->actingAs($this->leser)->getJson('/vault/api/quelle/datei?scope=extern:grafik&path=liste.md')->assertOk()->assertJsonPath('content', 'Inhalt der Freigabe');
    $this->actingAs($this->leser)->post('/vault/api/quelle/hochladen?scope=extern:grafik', ['file' => UploadedFile::fake()->createWithContent('liste.md', 'y')], ['Accept' => 'application/json'])->assertForbidden();

    $pfad = $this->actingAs($this->editor)->post('/vault/api/quelle/hochladen?scope=extern:grafik', ['file' => UploadedFile::fake()->createWithContent('liste.md', 'y')], ['Accept' => 'application/json'])->assertOk()->json('path');

    expect($pfad)->toBe('liste-1.md')
        ->and(file_get_contents($this->imSpeicher.'/liste.md'))->toBe('Inhalt der Freigabe');
});

it('gibt es ohne vault.quellen nicht', function () {
    config(['vault.quellen' => false]);

    $this->actingAs($this->editor)->getJson('/vault/api/baum?scope=extern:grafik')->assertNotFound();
});

/*
 * 0.6.4 (aibrain-e1 F52b): Quellen haben keinen Graphen — 404 statt 500 aus
 * der Ablage. Und der Bereichsreiter erfaehrt, ob eine Quelle erreichbar ist.
 */
it('antwortet beim Graphen einer Quelle mit 404 statt 500', function () {
    $this->actingAs($this->editor)->getJson('/vault/api/verweise?scope=extern:grafik')
        ->assertNotFound()
        ->assertJsonPath('message', 'Quellen haben keinen Graphen.');
    $this->actingAs($this->editor)->getJson('/vault/api/verweise?scope=extern:grafik&path=liste.md')->assertNotFound();
});

it('sagt im Bereichsreiter, ob eine Quelle erreichbar ist, und nur bei Quellen', function () {
    $bereiche = collect(app(Navigation::class)->bereiche($this->editor))->keyBy('slug');

    expect($bereiche['extern:grafik']['erreichbar'] ?? null)->toBeTrue()
        ->and($bereiche['extern:weg']['erreichbar'] ?? null)->toBeFalse()
        ->and($bereiche['private'])->not->toHaveKey('erreichbar');

    // Bricht die Einhaengung weg, sagt der Reiter es beim naechsten Laden.
    $this->quelle->forceFill(['mount_path' => $this->nichtEingehaengt])->save();
    expect(collect(app(Navigation::class)->bereiche($this->editor))->keyBy('slug')['extern:grafik']['erreichbar'])->toBeFalse();
});

/*
 * 0.6.5 (aibrain-e1, AI-Brain-Prod): Anmerkungen, Vorschaubilder und die
 * EuroOffice-Konfiguration liefen bei Quellen mit 500 in die Ablage. Jetzt
 * lesbare Antworten - ein 500 gibt es an keiner Stelle.
 */
it('antwortet bei Quellen auf Anmerkungen, Vorschau und Office ohne 500', function () {
    file_put_contents($this->imSpeicher.'/buch.epub', 'epub');
    file_put_contents($this->imSpeicher.'/plan.pdf', '%PDF-1.4');
    file_put_contents($this->imSpeicher.'/brief.docx', 'docx');
    config()->set('vault.eurooffice.enabled', true);
    config()->set('vault.eurooffice.public_url', 'https://office.example');
    config()->set('vault.eurooffice.jwt_secret', str_repeat('a', 64));

    // PDF: dort lief der Pfad ueber die Ablage (gemessener 500); EPUB las schon vorher ohne Ablage.
    $this->actingAs($this->editor)->getJson('/vault/api/anmerkungen?scope=extern:grafik&path=plan.pdf')
        ->assertOk()->assertJsonPath('type', 'pdf')->assertJsonPath('annotations', []);
    $this->actingAs($this->editor)->getJson('/vault/api/anmerkungen?scope=extern:grafik&path=buch.epub')
        ->assertOk()->assertJsonPath('annotations', []);
    $this->actingAs($this->editor)->postJson('/vault/api/anmerkungen?scope=extern:grafik', ['path' => 'buch.epub', 'quote' => 'x'])
        ->assertForbidden();
    $this->actingAs($this->editor)->patchJson('/vault/api/anmerkungen?scope=extern:grafik', ['path' => 'buch.epub', 'id' => 'a'])
        ->assertForbidden();
    $this->actingAs($this->editor)->deleteJson('/vault/api/anmerkungen?scope=extern:grafik', ['path' => 'buch.epub', 'id' => 'a'])
        ->assertForbidden();
    $this->actingAs($this->editor)->getJson('/vault/api/vorschau?scope=extern:grafik&path=fotos/2026/bild.jpg')
        ->assertNotFound();
    // Seit 0.7.1 geht Office in Quellen (Tests unten) - hier nur: kein 500.
    $this->actingAs($this->editor)->getJson('/vault/api/eurooffice/konfiguration?scope=extern:grafik&path=brief.docx')
        ->assertOk();

    expect(file_exists($this->imSpeicher.'/buch.epub'))->toBeTrue();
});

/*
 * 0.7.1 (Keeper-FREI, mit aibrain-e1 abgestimmt): EuroOffice in Quellen.
 * Lesen und Schreiben ueber Quellen statt Ablage, ohne Versionen; edit nur mit
 * Schreibrecht; bricht die Quelle weg, lehnt der Rueckweg ab und schreibt NIE
 * woanders hin (AI Brain #1625).
 */
function officeInQuellen(): Client
{
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

    return app(Client::class);
}

function rueckwegNutzlast(Client $client): array
{
    $rumpf = ['status' => 2, 'url' => 'https://manager.example.test/eurooffice/cache/files/abc/output.docx', 'users' => ['editor']];
    $rumpf['token'] = $client->sign($rumpf);

    return $rumpf;
}

it('oeffnet Office in einer Quelle zum Bearbeiten nur mit Schreibrecht', function () {
    officeInQuellen();
    file_put_contents($this->imSpeicher.'/brief.docx', 'alt');
    $adresse = '/vault/api/eurooffice/konfiguration?scope=extern:grafik&path=brief.docx';

    $this->actingAs($this->editor)->getJson($adresse)->assertOk()
        ->assertJsonPath('config.editorConfig.mode', 'edit')
        ->assertJsonPath('config.editorConfig.callbackUrl', fn ($url) => is_string($url) && str_contains($url, '/rueckweg/'));
    $this->actingAs($this->leser)->getJson($adresse)->assertOk()
        ->assertJsonPath('config.editorConfig.mode', 'view')
        ->assertJsonMissingPath('config.editorConfig.callbackUrl');
});

it('liefert dem Document Server die Datei aus der Quelle', function () {
    $client = officeInQuellen();
    file_put_contents($this->imSpeicher.'/brief.docx', 'INHALT-AUS-DER-QUELLE');
    $token = $client->issueDocumentToken('source:grafik', 'brief.docx', 10);

    expect($this->get("/vault/eurooffice/datei/{$token}")->assertOk()->streamedContent())->toBe('INHALT-AUS-DER-QUELLE');
});

it('speichert ueber den Rueckweg in die Quelle, ohne Version', function () {
    $client = officeInQuellen();
    Http::fake(['*' => Http::response('NEU', 200)]);
    file_put_contents($this->imSpeicher.'/brief.docx', 'alt');
    $ziel = $client->sign(['scope' => 'source:grafik', 'path' => 'brief.docx', 'user' => (string) $this->editor->id, 'iat' => time(), 'exp' => time() + 600]);

    $this->postJson("/vault/eurooffice/rueckweg/{$ziel}", rueckwegNutzlast($client))->assertJson(['error' => 0]);

    expect(file_get_contents($this->imSpeicher.'/brief.docx'))->toBe('NEU')
        ->and(VaultVersion::query()->count())->toBe(0);
});

it('lehnt den Rueckweg ab, wenn die Quelle waehrend der Sitzung wegbricht, und schreibt nirgendwo anders hin', function () {
    $client = officeInQuellen();
    Http::fake(['*' => Http::response('NEU', 200)]);
    file_put_contents($this->imSpeicher.'/brief.docx', 'alt');
    $ziel = $client->sign(['scope' => 'source:grafik', 'path' => 'brief.docx', 'user' => (string) $this->editor->id, 'iat' => time(), 'exp' => time() + 600]);

    $this->quelle->forceFill(['mount_path' => $this->nichtEingehaengt])->save();

    $this->postJson("/vault/eurooffice/rueckweg/{$ziel}", rueckwegNutzlast($client))->assertJson(['error' => 1]);

    expect(file_get_contents($this->imSpeicher.'/brief.docx'))->toBe('alt')
        ->and(File::files($this->nichtEingehaengt))->toBe([])
        ->and(VaultVersion::query()->count())->toBe(0)
        ->and(app(Vault::class)->existiert(Bereich::person($this->editor->id), 'brief.docx'))->toBeFalse();
});
