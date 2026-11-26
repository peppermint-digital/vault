<?php

use Illuminate\Http\UploadedFile;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Models\VaultShare;
use Peppermint\Vault\Models\VaultSpace;
use Peppermint\Vault\Models\VaultVersion;
use Peppermint\Vault\Vault;

beforeEach(function () {
    $this->vault = app(Vault::class);
    VaultSpace::query()->create(['name' => 'Allgemein', 'slug' => 'allgemein', 'is_global' => true]);
});

it('verlangt eine Anmeldung', function () {
    $this->getJson('/vault/api/baum')->assertUnauthorized();
});

it('zeigt den eigenen Bereich und liest Dateien mit Fassung und ID', function () {
    $person = $this->person();
    $this->vault->schreiben(Bereich::person($person->id), 'a.md', '# A');

    $this->actingAs($person)->getJson('/vault/api/baum')
        ->assertOk()
        ->assertJsonPath('bereich.persoenlich', true)
        ->assertJsonPath('bereich.darfSchreiben', true)
        ->assertJsonPath('baum.0.path', 'a.md');

    $this->actingAs($person)->getJson('/vault/api/datei?path=a.md')
        ->assertOk()
        ->assertJsonPath('content', '# A')
        ->assertJsonPath('version', md5('# A'))
        ->assertJsonStructure(['file_id']);
});

it('verbirgt fremde Raeume mit 404 und verweigert Schreiben ohne Recht mit 403', function () {
    VaultSpace::query()->create(['name' => 'Geheim', 'slug' => 'geheim']);
    $person = $this->person();

    $this->actingAs($person)->getJson('/vault/api/baum?scope=geheim')->assertNotFound();
    $this->actingAs($person)->getJson('/vault/api/baum?scope=gibtsnicht')->assertNotFound();
    $this->actingAs($person)->getJson('/vault/api/baum?scope=allgemein')->assertOk()->assertJsonPath('bereich.darfSchreiben', false);
    $this->actingAs($person)->postJson('/vault/api/datei?scope=allgemein', ['path' => 'x.md', 'type' => 'file', 'content' => 'x'])
        ->assertForbidden();
});

it('ueberschreibt beim Anlegen nicht', function () {
    $person = $this->person();
    $this->vault->schreiben(Bereich::person($person->id), 'a.md', 'alt');

    $this->actingAs($person)->postJson('/vault/api/datei', ['path' => 'a.md', 'type' => 'file', 'content' => 'neu'])
        ->assertStatus(422);

    expect($this->vault->lesen(Bereich::person($person->id), 'a.md'))->toBe('alt');
});

it('meldet einen Konflikt statt fremde Aenderungen zu ueberschreiben', function () {
    $person = $this->person();
    $this->vault->schreiben(Bereich::person($person->id), 'a.md', 'stand-2');

    $this->actingAs($person)->patchJson('/vault/api/datei', ['path' => 'a.md', 'content' => 'meins', 'base_version' => md5('stand-1')])
        ->assertStatus(409)
        ->assertJsonPath('current_content', 'stand-2');

    $this->actingAs($person)->patchJson('/vault/api/datei', ['path' => 'a.md', 'content' => 'meins', 'base_version' => md5('stand-2')])
        ->assertOk()
        ->assertJsonPath('version', md5('meins'));
});

it('loescht in den Papierkorb und holt zurueck — nur im eigenen Bereich', function () {
    $person = $this->person();
    $fremd = $this->person('Fremd');
    $this->vault->schreiben(Bereich::person($person->id), 'a.md', 'x');

    $id = $this->actingAs($person)->deleteJson('/vault/api/datei', ['path' => 'a.md'])->assertOk()->json('trash_id');

    $this->actingAs($person)->getJson('/vault/api/papierkorb')->assertOk()->assertJsonPath('eintraege.0.original_path', 'a.md');
    $this->actingAs($fremd)->postJson("/vault/api/papierkorb/{$id}/wiederherstellen")->assertNotFound();
    $this->actingAs($person)->postJson("/vault/api/papierkorb/{$id}/wiederherstellen")->assertOk()->assertJsonPath('path', 'a.md');
});

it('gibt Versionen nur an Berechtigte heraus', function () {
    $person = $this->person();
    $fremd = $this->person('Fremd');
    $this->vault->schreiben(Bereich::person($person->id), 'a.md', 'eins');
    $this->vault->schreiben(Bereich::person($person->id), 'a.md', 'zwei');
    $erste = VaultVersion::query()->orderBy('id')->first();

    $this->actingAs($person)->getJson('/vault/api/versionen?path=a.md')->assertOk()->assertJsonCount(2, 'versions');
    $this->actingAs($fremd)->getJson("/vault/api/versionen/{$erste->id}")->assertNotFound();
    $this->actingAs($person)->postJson("/vault/api/versionen/{$erste->id}/wiederherstellen")->assertOk();

    expect($this->vault->lesen(Bereich::person($person->id), 'a.md'))->toBe('eins');
});

it('liefert HTML nur mit Content-Security-Policy aus', function () {
    $person = $this->person();
    $this->vault->schreiben(Bereich::person($person->id), 'seite.html', '<script>fetch("/x")</script>');

    $antwort = $this->actingAs($person)->get('/vault/api/roh?path=seite.html')->assertOk();

    expect($antwort->headers->get('Content-Security-Policy'))->toContain('connect-src https://unpkg.com')
        ->and($antwort->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('laedt hoch und verschiebt', function () {
    $person = $this->person();

    $pfad = $this->actingAs($person)->post('/vault/api/hochladen', ['file' => UploadedFile::fake()->createWithContent('Bild 1.png', 'x'), 'folder' => 'anhang'], ['Accept' => 'application/json'])
        ->assertOk()->json('path');
    $this->actingAs($person)->postJson('/vault/api/verschieben', ['from' => $pfad, 'to' => 'bilder/bild.png'])->assertOk();

    expect($pfad)->toBe('anhang/Bild 1.png')
        ->and($this->vault->existiert(Bereich::person($person->id), 'bilder/bild.png'))->toBeTrue();
});

it('legt Freigaben nur mit Schreibrecht an und laesst nur die eigenen zurueckziehen', function () {
    $schreiber = $this->person('Schreiber');
    $schreiber->rechte = ['shared-vault.edit'];
    $leser = $this->person('Leser');
    $this->vault->schreiben(Bereich::global(), 'info.md', 'x');

    $this->actingAs($leser)->postJson('/vault/api/freigaben?scope=allgemein', ['path' => 'info.md'])->assertForbidden();

    $id = $this->actingAs($schreiber)->postJson('/vault/api/freigaben?scope=allgemein', ['path' => 'info.md', 'password' => 'geheim1'])
        ->assertCreated()
        ->assertJsonPath('share.requires_password', true)
        ->json('share.id');

    expect(VaultShare::query()->find($id)->area_key)->toBe('_shared');

    $this->actingAs($leser)->deleteJson("/vault/api/freigaben/{$id}")->assertForbidden();
    $this->actingAs($schreiber)->deleteJson("/vault/api/freigaben/{$id}")->assertOk();
    $this->actingAs($schreiber)->getJson('/vault/api/freigaben?scope=allgemein&path=info.md')->assertOk()->assertJsonCount(0, 'shares');
});

it('schaltet die Routen ab, wenn das Produkt eigene hat', function () {
    expect(app('router')->has('vault.api.baum'))->toBeTrue();
});
