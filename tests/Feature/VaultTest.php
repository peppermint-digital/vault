<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Events\DateiGeloescht;
use Peppermint\Vault\Events\DateiGespeichert;
use Peppermint\Vault\Events\DateiVerschoben;
use Peppermint\Vault\Events\KontoAngelegt;
use Peppermint\Vault\Models\VaultFile;
use Peppermint\Vault\Models\VaultTrash;
use Peppermint\Vault\Models\VaultVersion;
use Peppermint\Vault\Vault;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

beforeEach(function () {
    $this->vault = app(Vault::class);
    $this->bereich = Bereich::person(1);
});

it('schreibt, liest und gibt der Datei eine feste ID', function () {
    $this->vault->schreiben($this->bereich, 'a/notiz.md', '# Hallo');

    expect($this->vault->lesen($this->bereich, 'a/notiz.md'))->toBe('# Hallo')
        ->and(VaultFile::query()->where('scope', 'user:1')->where('path', 'a/notiz.md')->count())->toBe(1);
});

it('schreibt keine Binaerdatei ueber den Text-Weg', function () {
    $this->vault->schreiben($this->bereich, 'tabelle.ods', 'kaputt');
})->throws(UnprocessableEntityHttpException::class);

it('legt Versionen an, ueberspringt gleiche Staende und beschneidet', function () {
    config(['vault.versionen.max_je_datei' => 2]);

    $this->vault->schreiben($this->bereich, 'n.md', 'eins');
    $this->vault->schreiben($this->bereich, 'n.md', 'eins');
    $this->vault->schreiben($this->bereich, 'n.md', 'zwei');
    $this->vault->schreiben($this->bereich, 'n.md', 'drei');

    expect(VaultVersion::query()->where('path', 'n.md')->orderBy('id')->pluck('content')->all())->toBe(['zwei', 'drei']);
});

it('versioniert Binaerdateien auf der Platte und raeumt beim Beschneiden mit auf', function () {
    config(['vault.versionen.max_binaer_je_datei' => 1]);
    $quelle = tempnam(sys_get_temp_dir(), 'bin');

    file_put_contents($quelle, 'stand-1');
    $this->vault->binaerSchreiben($this->bereich, 'b.xlsx', $quelle);
    $erster = VaultVersion::query()->first()->absoluteStoragePath();

    file_put_contents($quelle, 'stand-2');
    $this->vault->binaerSchreiben($this->bereich, 'b.xlsx', $quelle);

    expect(VaultVersion::query()->count())->toBe(1)
        ->and(file_exists($erster))->toBeFalse()
        ->and(file_get_contents(VaultVersion::query()->first()->absoluteStoragePath()))->toBe('stand-2');

    unlink($quelle);
});

it('behaelt ID und Versionen beim Umbenennen eines Ordners', function () {
    Event::fake([DateiVerschoben::class]);
    $this->vault->schreiben($this->bereich, 'alt/n.md', 'x');
    $id = $this->vault->kennung()->id($this->bereich, 'alt/n.md');

    $this->vault->verschieben($this->bereich, 'alt', 'neu');

    expect($this->vault->kennung()->id($this->bereich, 'neu/n.md'))->toBe($id)
        ->and($this->vault->versionen()->liste($this->bereich, 'neu/n.md'))->toHaveCount(1);
    Event::assertDispatched(DateiVerschoben::class, fn ($e) => $e->istOrdner && $e->nach === 'neu');
});

it('verwechselt beim Verschieben keine Pfade mit Unterstrich', function () {
    // `_` ist in LIKE ein Platzhalter: `a_b/%` traefe auch `axb/…`.
    $this->vault->schreiben($this->bereich, 'a_b/n.md', '1');
    $this->vault->schreiben($this->bereich, 'axb/n.md', '2');
    $fremd = $this->vault->kennung()->id($this->bereich, 'axb/n.md');

    $this->vault->verschieben($this->bereich, 'a_b', 'c');

    expect($this->vault->kennung()->id($this->bereich, 'axb/n.md'))->toBe($fremd);
});

it('legt Geloeschtes in den Papierkorb und holt es mit derselben ID zurueck', function () {
    Event::fake([DateiGeloescht::class]);
    $this->vault->schreiben($this->bereich, 'n.md', 'x');
    $id = $this->vault->kennung()->id($this->bereich, 'n.md');

    $eintrag = $this->vault->loeschen($this->bereich, 'n.md');

    expect($this->vault->existiert($this->bereich, 'n.md'))->toBeFalse()
        ->and(VaultFile::query()->find($id)->istGeloescht())->toBeTrue();
    Event::assertDispatched(DateiGeloescht::class);

    $ziel = $this->vault->papierkorb()->zurueckholen($eintrag);

    expect($ziel)->toBe('n.md')
        ->and($this->vault->kennung()->id($this->bereich, 'n.md'))->toBe($id)
        ->and(VaultTrash::query()->count())->toBe(0);
});

it('ueberschreibt beim Zurueckholen nichts, was inzwischen dort liegt', function () {
    $this->vault->schreiben($this->bereich, 'n.md', 'alt');
    $eintrag = $this->vault->loeschen($this->bereich, 'n.md');
    $this->vault->schreiben($this->bereich, 'n.md', 'neu');

    $ziel = $this->vault->papierkorb()->zurueckholen($eintrag);

    expect($ziel)->toBe('n-wiederhergestellt.md')
        ->and($this->vault->lesen($this->bereich, 'n.md'))->toBe('neu')
        ->and($this->vault->lesen($this->bereich, $ziel))->toBe('alt');
});

it('entfernt beim endgueltigen Leeren auch die Datei-IDs', function () {
    $this->vault->schreiben($this->bereich, 'ordner/n.md', 'x');
    $eintrag = $this->vault->loeschen($this->bereich, 'ordner');

    $this->vault->papierkorb()->leeren($eintrag);

    expect(VaultFile::query()->count())->toBe(0)
        ->and(file_exists($eintrag->absoluterPfad()))->toBeFalse();
});

it('raeumt abgelaufene Papierkorb-Eintraege per Befehl auf', function () {
    $this->vault->schreiben($this->bereich, 'n.md', 'x');
    $eintrag = $this->vault->loeschen($this->bereich, 'n.md');
    $eintrag->forceFill(['deleted_at' => now()->subDays(31)])->save();

    $this->artisan('vault:papierkorb-leeren')->assertSuccessful();

    expect(VaultTrash::query()->count())->toBe(0);
});

it('ueberschreibt beim Hochladen nie', function () {
    $erste = $this->vault->hochladen($this->bereich, 'anhang', UploadedFile::fake()->createWithContent('bild.png', 'a'));
    $zweite = $this->vault->hochladen($this->bereich, 'anhang', UploadedFile::fake()->createWithContent('bild.png', 'b'));

    expect($erste)->toBe('anhang/bild.png')
        ->and($zweite)->toBe('anhang/bild-1.png')
        ->and($this->vault->lesen($this->bereich, $erste))->toBe('a');
});

it('findet nach Titel und Inhalt, Titeltreffer zuerst, verborgene Wurzeldateien nie', function () {
    config(['vault.verborgene_wurzeldateien' => ['CLAUDE.md']]);
    $this->vault->schreiben($this->bereich, 'inhalt.md', 'Hier steht Apfel drin.');
    $this->vault->schreiben($this->bereich, 'apfel.md', 'nichts');
    $this->vault->schreiben($this->bereich, 'CLAUDE.md', 'Apfel');

    $treffer = $this->vault->suchen($this->bereich, 'apfel');

    expect(array_column($treffer, 'path'))->toBe(['apfel.md', 'inhalt.md'])
        ->and(collect($this->vault->baum($this->bereich))->pluck('path')->all())->not->toContain('CLAUDE.md');
});

it('verbirgt Wurzeldateien nur im persoenlichen Bereich', function () {
    config(['vault.verborgene_wurzeldateien' => ['CLAUDE.md']]);
    $this->vault->schreiben(Bereich::global(), 'CLAUDE.md', 'x');

    expect(collect($this->vault->baum(Bereich::global()))->pluck('path')->all())->toContain('CLAUDE.md');
});

it('stellt eine Version wieder her, ohne die aktuelle zu verlieren', function () {
    $this->vault->schreiben($this->bereich, 'n.md', 'eins');
    $this->vault->schreiben($this->bereich, 'n.md', 'zwei');
    $erste = VaultVersion::query()->orderBy('id')->first();

    $this->vault->versionWiederherstellen($erste->id);

    expect($this->vault->lesen($this->bereich, 'n.md'))->toBe('eins')
        ->and(VaultVersion::query()->pluck('content')->all())->toBe(['eins', 'zwei', 'eins']);
});

it('legt ein Konto nur an, wenn es fehlt, und meldet es', function () {
    Event::fake([KontoAngelegt::class]);
    $person = $this->person();

    expect($this->vault->kontoAnlegen($person))->toBeTrue()
        ->and($this->vault->kontoAnlegen($person))->toBeFalse();
    Event::assertDispatchedTimes(KontoAngelegt::class, 2);
    Event::assertDispatched(KontoAngelegt::class, fn ($e) => $e->neu && $e->bereich->scope() === 'user:'.$person->id);
});

it('meldet jedes Speichern mit Datei-ID', function () {
    Event::fake([DateiGespeichert::class]);

    $this->vault->schreiben($this->bereich, 'n.md', 'x', 'assistant');

    Event::assertDispatched(DateiGespeichert::class, fn ($e) => $e->dateiId !== null && $e->autor === 'assistant');
});
