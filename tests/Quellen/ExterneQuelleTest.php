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
use Peppermint\Vault\Ablage;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Contracts\Vaultrechte;
use Peppermint\Vault\Models\VaultSource;
use Peppermint\Vault\Models\VaultVersion;
use Peppermint\Vault\Quellen\Quellen;
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
