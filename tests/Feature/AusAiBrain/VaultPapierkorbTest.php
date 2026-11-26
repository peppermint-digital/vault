<?php

/* Aus AI Brain tests/Feature/VaultPapierkorbTest.php (Stand 118ee2b2). */

use Peppermint\Vault\Ablage;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Models\VaultTrash;
use Peppermint\Vault\Vault;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    $this->vault = app(Vault::class);
    $this->bereich = Bereich::person(1);
});

it('faengt Binaerdateien und ganze Ordner auf', function () {
    $quelle = tempnam(sys_get_temp_dir(), 'bin');
    file_put_contents($quelle, "\x00\x01binaer");
    $this->vault->binaerSchreiben($this->bereich, 'ordner/tabelle.xlsx', $quelle);
    $this->vault->schreiben($this->bereich, 'ordner/notiz.md', 'x');

    $eintrag = $this->vault->loeschen($this->bereich, 'ordner');

    expect($eintrag->istOrdner())->toBeTrue()
        ->and(file_get_contents($eintrag->absoluterPfad().'/tabelle.xlsx'))->toBe("\x00\x01binaer");
    unlink($quelle);
});

it('laesst zwei gleichnamige Dateien nebeneinander liegen', function () {
    $this->vault->schreiben($this->bereich, 'a/notiz.md', 'eins');
    $this->vault->schreiben($this->bereich, 'b/notiz.md', 'zwei');

    $erster = $this->vault->loeschen($this->bereich, 'a/notiz.md');
    $zweiter = $this->vault->loeschen($this->bereich, 'b/notiz.md');

    expect(file_get_contents($erster->absoluterPfad()))->toBe('eins')
        ->and(file_get_contents($zweiter->absoluterPfad()))->toBe('zwei');
});

it('meldet einen Eintrag, dessen Inhalt fehlt, statt Erfolg vorzutaeuschen', function () {
    $this->vault->schreiben($this->bereich, 'a.md', 'x');
    $eintrag = $this->vault->loeschen($this->bereich, 'a.md');
    unlink($eintrag->absoluterPfad());

    expect(fn () => $this->vault->papierkorb()->zurueckholen($eintrag))->toThrow(NotFoundHttpException::class)
        ->and(VaultTrash::query()->count())->toBe(0);
});

it('haelt den Papierkorb getrennt nach Bereich', function () {
    $this->vault->schreiben($this->bereich, 'a.md', 'x');
    $this->vault->schreiben(Bereich::global(), 'b.md', 'x');
    $this->vault->loeschen($this->bereich, 'a.md');
    $this->vault->loeschen(Bereich::global(), 'b.md');

    expect($this->vault->papierkorb()->liste($this->bereich)->pluck('original_path')->all())->toBe(['a.md'])
        ->and($this->vault->papierkorb()->liste(Bereich::global())->pluck('original_path')->all())->toBe(['b.md']);
});

it('raeumt nur auf, was abgelaufen ist, und gar nicht ohne Frist', function () {
    $this->vault->schreiben($this->bereich, 'alt.md', 'x');
    $this->vault->schreiben($this->bereich, 'neu.md', 'x');
    $this->vault->loeschen($this->bereich, 'alt.md')->forceFill(['deleted_at' => now()->subDays(40)])->save();
    $this->vault->loeschen($this->bereich, 'neu.md');

    config(['vault.papierkorb.tage' => 0]);
    $this->artisan('vault:papierkorb-leeren')->assertSuccessful();
    expect(VaultTrash::query()->count())->toBe(2);

    config(['vault.papierkorb.tage' => 30]);
    $this->artisan('vault:papierkorb-leeren')->assertSuccessful();
    expect(VaultTrash::query()->pluck('original_path')->all())->toBe(['neu.md']);
});

it('legt den Papierkorb NICHT in den Vault-Baum', function () {
    $ablage = app(Ablage::class);

    expect(str_starts_with($ablage->papierkorbPfad().'/', $ablage->wurzel().'/'))->toBeFalse()
        ->and(str_starts_with($ablage->versionsPfad().'/', $ablage->wurzel().'/'))->toBeFalse();
});
