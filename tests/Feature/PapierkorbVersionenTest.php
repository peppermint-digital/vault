<?php

use Illuminate\Support\Facades\File;
use Peppermint\Vault\Ablage;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Models\VaultTrash;
use Peppermint\Vault\Models\VaultVersion;
use Peppermint\Vault\Vault;
use Peppermint\Vault\Versionen;

/*
 * #10293: Versionen haengen an Bereich + Pfad, nicht an der Datei. Ohne
 * Mitnahme in den Papierkorb erbte eine neue Datei am alten Ort die
 * Geschichte der geloeschten, und endgueltig Geloeschtes blieb als Version
 * stehen.
 */

beforeEach(function () {
    $this->vault = app(Vault::class);
    $this->bereich = Bereich::person(1);
});

function versionenUnter(string $pfad): array
{
    return VaultVersion::query()->where('scope', 'user:1')->where('path', $pfad)->orderBy('id')->pluck('content')->all();
}

it('nimmt die Versionen beim Loeschen mit in den Papierkorb', function () {
    foreach (['eins', 'zwei', 'drei'] as $stand) {
        $this->vault->schreiben($this->bereich, 'n.md', $stand);
    }

    $eintrag = $this->vault->loeschen($this->bereich, 'n.md');

    expect(versionenUnter('n.md'))->toBe([])
        ->and(versionenUnter(Versionen::papierkorbPfad($eintrag->id, 'n.md')))->toBe(['eins', 'zwei', 'drei']);
});

it('laesst eine neue Datei am alten Ort mit eigener Geschichte beginnen', function () {
    $this->vault->schreiben($this->bereich, 'n.md', 'geheim');
    $this->vault->loeschen($this->bereich, 'n.md');

    $this->vault->schreiben($this->bereich, 'n.md', 'neu');

    expect($this->vault->versionen()->liste($this->bereich, 'n.md')->count())->toBe(1)
        ->and(versionenUnter('n.md'))->toBe(['neu']);
});

it('bringt die Versionen beim Zurueckholen an den tatsaechlichen Ort', function () {
    $this->vault->schreiben($this->bereich, 'n.md', 'alt');
    $eintrag = $this->vault->loeschen($this->bereich, 'n.md');
    $this->vault->schreiben($this->bereich, 'n.md', 'neu');

    $ziel = $this->vault->papierkorb()->zurueckholen($eintrag);

    expect($ziel)->toBe('n-wiederhergestellt.md')
        ->and(versionenUnter($ziel))->toBe(['alt'])
        ->and(versionenUnter('n.md'))->toBe(['neu']);
});

it('entfernt beim endgueltigen Leeren die Versionen samt Blobs, aber nicht die der neuen Datei', function () {
    $this->vault->schreiben($this->bereich, 'n.md', 'alt');
    $absolut = app(Ablage::class)->aufloesen($this->bereich, 'n.md');
    $this->vault->versionen()->binaer($this->bereich, 'n.md', $absolut, 'probe');
    $blob = VaultVersion::query()->whereNotNull('storage_path')->sole()->absoluteStoragePath();
    expect(is_file($blob))->toBeTrue();

    $eintrag = $this->vault->loeschen($this->bereich, 'n.md');
    $this->vault->schreiben($this->bereich, 'n.md', 'neu');

    $this->vault->papierkorb()->leeren($eintrag);

    expect(VaultVersion::query()->where('path', 'like', Versionen::PAPIERKORB.'%')->count())->toBe(0)
        ->and(is_file($blob))->toBeFalse()
        ->and(versionenUnter('n.md'))->toBe(['neu']);
});

it('nimmt bei einem Ordner die Versionen aller Dateien darunter mit', function () {
    $this->vault->schreiben($this->bereich, 'o/a.md', 'a');
    $this->vault->schreiben($this->bereich, 'o/u/b.md', 'b');
    $this->vault->schreiben($this->bereich, 'oX.md', 'nachbar');

    $eintrag = $this->vault->loeschen($this->bereich, 'o');

    expect(VaultVersion::query()->where('path', 'like', Versionen::PAPIERKORB.'%')->count())->toBe(2)
        ->and(versionenUnter('oX.md'))->toBe(['nachbar']);

    $this->vault->papierkorb()->zurueckholen($eintrag);

    expect(versionenUnter('o/a.md'))->toBe(['a'])
        ->and(versionenUnter('o/u/b.md'))->toBe(['b']);

    $zweiter = $this->vault->loeschen($this->bereich, 'o');
    $this->vault->papierkorb()->leeren($zweiter);

    expect(VaultVersion::query()->count())->toBe(1);
});

it('laesst nach Zurueckholen und Leeren keinen leeren Papierkorb-Ordner zurueck', function () {
    $wurzel = app(Ablage::class)->papierkorbPfad();
    $this->vault->schreiben($this->bereich, 'a.md', 'a');
    $this->vault->schreiben($this->bereich, 'b.md', 'b');

    $this->vault->papierkorb()->zurueckholen($this->vault->loeschen($this->bereich, 'a.md'));
    $this->vault->papierkorb()->leeren($this->vault->loeschen($this->bereich, 'b.md'));

    expect(glob($wurzel.'/*', GLOB_ONLYDIR))->toBe([])
        ->and(is_dir($wurzel))->toBeTrue();
});

it('legt fuer eine leere neue Datei keine leere Version an', function () {
    $this->vault->schreiben($this->bereich, 'leer.md', '');

    expect(versionenUnter('leer.md'))->toBe([]);

    $this->vault->schreiben($this->bereich, 'leer.md', 'jetzt mit Inhalt');
    $this->vault->schreiben($this->bereich, 'leer.md', '');

    expect(versionenUnter('leer.md'))->toBe(['jetzt mit Inhalt', '']);
});

it('zaehlt den Altbestand nur und schreibt nichts', function () {
    $this->vault->schreiben($this->bereich, 'da.md', 'x');
    $this->vault->schreiben($this->bereich, 'liegt.md', 'y');
    $this->vault->loeschen($this->bereich, 'liegt.md');
    // Altbestand wie vor 0.4.0: Version ohne Datei, und ein leerer Papierkorb-Ordner.
    VaultVersion::query()->create(['scope' => 'user:1', 'path' => 'weg.md', 'content' => 'z', 'size' => 1, 'created_at' => now()]);
    File::ensureDirectoryExists(app(Ablage::class)->papierkorbPfad().'/0123456789abcdef');
    $vorher = VaultVersion::query()->count();

    $this->artisan('vault:versionen-aufraeumen', ['--trocken' => true])
        ->expectsOutput('user: im_papierkorb=1 mit_datei=1 verwaist=1')
        ->expectsOutput('papierkorb_leere_ordner=1')
        ->assertSuccessful();

    expect(VaultVersion::query()->count())->toBe($vorher)
        ->and(VaultTrash::query()->count())->toBe(1);
});

it('verweigert den Aufraeum-Befehl ohne --trocken', function () {
    $this->artisan('vault:versionen-aufraeumen')->assertFailed();
});
