<?php

/*
 * Aus AI Brain tests/Feature/AnnotationControllerTest.php und
 * PdfEpubAnnotationTest.php (Stand 118ee2b2), dazu Vorschaubilder (#6222).
 * Die PDF-Faelle brauchen PyMuPDF: Sie nehmen das venv aus VAULT_TEST_PDF_PYTHON
 * oder das von AI Brain auf diesem Rechner und werden sonst uebersprungen.
 */

use Illuminate\Support\Facades\Process;
use Peppermint\Vault\Ablage;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Models\VaultSpace;
use Peppermint\Vault\Models\VaultVersion;
use Peppermint\Vault\Vault;
use Peppermint\Vault\Vorschaubilder;

function pdfPython(): ?string
{
    $kandidat = getenv('VAULT_TEST_PDF_PYTHON') ?: '/home/codingmachine/Development/ai-brain/storage/app/python/venv/bin/python';

    return is_file($kandidat) ? $kandidat : null;
}

beforeEach(function () {
    $this->vault = app(Vault::class);
    $this->person = $this->person('Anna');
    $this->bereich = Bereich::person($this->person->id);
});

function pdfAnlegen(Vault $vault, Bereich $bereich, string $pfad): void
{
    $ziel = $vault->absolut($bereich, $pfad);
    @mkdir(dirname($ziel), 0755, true);
    Process::run([pdfPython(), '-c', 'import fitz,sys; d=fitz.open(); p=d.new_page(); p.insert_text((72,72),"Hallo Welt im Vertrag"); d.save(sys.argv[1])', $ziel])->throw();
}

it('markiert Text in einer PDF, legt dafuer eine Version an und spiegelt es in den Steckbrief', function () {
    config(['vault.anmerkungen.python' => pdfPython()]);
    pdfAnlegen($this->vault, $this->bereich, 'akten/vertrag.pdf');

    $this->actingAs($this->person)->postJson('/vault/api/anmerkungen', ['path' => 'akten/vertrag.pdf', 'page' => 1, 'text' => 'Hallo Welt', 'comment' => 'pruefen'])
        ->assertOk()
        ->assertJsonPath('type', 'pdf')
        ->assertJsonCount(1, 'annotations')
        ->assertJsonPath('steckbrief', 'akten/vertrag.md');

    expect(VaultVersion::query()->where('path', 'akten/vertrag.pdf')->count())->toBe(1)
        ->and($this->vault->lesen($this->bereich, 'akten/vertrag.md'))->toContain('ai-brain:annot:vertrag.pdf')->toContain('pruefen');
})->skip(fn () => pdfPython() === null, 'PyMuPDF nicht vorhanden');

it('laesst Leser Anmerkungen sehen, aber nicht setzen', function () {
    config(['vault.anmerkungen.python' => pdfPython()]);
    VaultSpace::query()->create(['name' => 'Allgemein', 'slug' => 'allgemein', 'is_global' => true]);
    pdfAnlegen($this->vault, Bereich::global(), 'info.pdf');

    $this->actingAs($this->person)->getJson('/vault/api/anmerkungen?scope=allgemein&path=info.pdf')->assertOk()->assertJsonCount(0, 'annotations');
    $this->actingAs($this->person)->postJson('/vault/api/anmerkungen?scope=allgemein', ['path' => 'info.pdf', 'page' => 1, 'text' => 'Hallo'])->assertForbidden();
})->skip(fn () => pdfPython() === null, 'PyMuPDF nicht vorhanden');

it('sagt ohne PyMuPDF lesbar ab', function () {
    config(['vault.anmerkungen.python' => null]);

    $this->actingAs($this->person)->getJson('/vault/api/anmerkungen?path=x.pdf')->assertStatus(503);
});

it('fuehrt EPUB-Anmerkungen in einer Datei daneben: anlegen, aendern, entfernen', function () {
    $ziel = $this->vault->absolut($this->bereich, 'buch.epub');
    file_put_contents($ziel, 'epub');

    $id = $this->actingAs($this->person)->postJson('/vault/api/anmerkungen', ['path' => 'buch.epub', 'quote' => 'Es war einmal', 'note' => 'Anfang', 'chapter' => '1'])
        ->assertOk()->assertJsonCount(1, 'annotations')->json('annotations.0.id');

    $this->actingAs($this->person)->patchJson('/vault/api/anmerkungen', ['path' => 'buch.epub', 'id' => $id, 'note' => 'geaendert', 'color' => 'green'])
        ->assertOk()->assertJsonPath('annotations.0.note', 'geaendert')->assertJsonPath('annotations.0.color', 'green');

    expect($this->vault->lesen($this->bereich, 'buch.md'))->toContain('geaendert');

    $this->actingAs($this->person)->deleteJson('/vault/api/anmerkungen', ['path' => 'buch.epub', 'id' => $id])->assertOk()->assertJsonCount(0, 'annotations');
    expect($this->vault->existiert($this->bereich, 'buch.epub.annot.json'))->toBeTrue();
});

it('lehnt andere Dateitypen und fehlende Pflichtangaben ab', function () {
    $this->actingAs($this->person)->getJson('/vault/api/anmerkungen?path=n.md')->assertStatus(422);
    $this->actingAs($this->person)->postJson('/vault/api/anmerkungen', ['path' => 'buch.epub'])->assertStatus(422)->assertJsonValidationErrors('quote');
});

it('erzeugt Vorschaubilder ausserhalb des Vaults, liefert SVG direkt und sonst nichts', function () {
    $bild = imagecreatetruecolor(800, 400);
    $pfad = $this->vault->absolut($this->bereich, 'foto.png');
    imagepng($bild, $pfad);
    file_put_contents($this->vault->absolut($this->bereich, 'logo.svg'), '<svg xmlns="http://www.w3.org/2000/svg"/>');
    $this->vault->schreiben($this->bereich, 'n.md', 'x');

    $antwort = $this->actingAs($this->person)->get('/vault/api/vorschau?path=foto.png')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    [$breite] = getimagesize($antwort->baseResponse->getFile()->getPathname());

    expect($breite)->toBe(320)
        ->and(str_starts_with(Vorschaubilder::basisPfad().'/', app(Ablage::class)->wurzel().'/'))->toBeFalse();

    $this->actingAs($this->person)->get('/vault/api/vorschau?path=logo.svg')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    $this->actingAs($this->person)->get('/vault/api/vorschau?path=n.md')->assertNotFound();
});
