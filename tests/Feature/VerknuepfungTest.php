<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Peppermint\Vault\Ablageregeln;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Contracts\Ablageregel;
use Peppermint\Vault\Models\VaultFile;
use Peppermint\Vault\Models\VaultLink;
use Peppermint\Vault\Tests\Fixtures\Aufgabe;
use Peppermint\Vault\Vault;
use Peppermint\Vault\Verknuepfung\Verknuepfungen;

beforeEach(function () {
    Schema::create('aufgaben', function (Blueprint $table) {
        $table->id();
        $table->string('titel');
        $table->timestamps();
    });

    app(Ablageregeln::class)->anmelden('aufgabe', new class implements Ablageregel
    {
        public function bereichFuer(Model $objekt): Bereich
        {
            return Bereich::geteilt('firma');
        }

        public function ordnerFuer(Model $objekt): string
        {
            return 'aufgaben/'.$objekt->titel;
        }
    });

    $this->verknuepfungen = app(Verknuepfungen::class);
});

it('legt eine Notiz am Ort der Ablage-Regel an und haengt sie an', function () {
    $aufgabe = Aufgabe::query()->create(['titel' => 'Angebot']);
    $person = $this->person();

    $datei = $this->verknuepfungen->hierAblegen($aufgabe, 'notiz.md', '# Notiz', $person);
    $zweite = $this->verknuepfungen->hierAblegen($aufgabe, 'notiz.md', '# Noch eine', $person);

    expect($datei->path)->toBe('aufgaben/Angebot/notiz.md')
        ->and($datei->scope)->toBe('area:firma')
        ->and($zweite->path)->toBe('aufgaben/Angebot/notiz-1.md')
        ->and(VaultLink::query()->first()->linkable_type)->toBe('aufgabe')
        ->and($this->verknuepfungen->dateienVon($aufgabe)->pluck('id')->all())->toBe([$datei->id, $zweite->id]);
});

it('zeigt angeheftete zuerst und verknuepft nicht doppelt', function () {
    $aufgabe = Aufgabe::query()->create(['titel' => 'A']);
    $vault = app(Vault::class);
    $vault->schreiben(Bereich::global(), 'eins.md', '1');
    $vault->schreiben(Bereich::global(), 'zwei.md', '2');
    $eins = VaultFile::query()->where('path', 'eins.md')->first();
    $zwei = VaultFile::query()->where('path', 'zwei.md')->first();

    $this->verknuepfungen->verknuepfen($eins, $aufgabe);
    $this->verknuepfungen->verknuepfen($zwei, $aufgabe, angeheftet: true);
    $this->verknuepfungen->verknuepfen($eins, $aufgabe);

    expect(VaultLink::query()->count())->toBe(2)
        ->and($this->verknuepfungen->dateienVon($aufgabe)->pluck('path')->all())->toBe(['zwei.md', 'eins.md']);
});

it('haelt die Verknuepfung ueber Umbenennen und Papierkorb hinweg', function () {
    $aufgabe = Aufgabe::query()->create(['titel' => 'A']);
    $datei = $this->verknuepfungen->hierAblegen($aufgabe, 'n.md', 'x');
    $vault = app(Vault::class);

    $vault->verschieben(Bereich::geteilt('firma'), 'aufgaben/A/n.md', 'aufgaben/A/umbenannt.md');
    expect($this->verknuepfungen->dateienVon($aufgabe)->first()->path)->toBe('aufgaben/A/umbenannt.md');

    $eintrag = $vault->loeschen(Bereich::geteilt('firma'), 'aufgaben/A/umbenannt.md');
    expect($this->verknuepfungen->dateienVon($aufgabe))->toHaveCount(0);

    $vault->papierkorb()->zurueckholen($eintrag);
    expect($this->verknuepfungen->dateienVon($aufgabe)->first()->id)->toBe($datei->id);
});

it('loest beim Loeschen des Objekts nur die Verknuepfung, die Datei bleibt', function () {
    $aufgabe = Aufgabe::query()->create(['titel' => 'A']);
    $datei = $this->verknuepfungen->hierAblegen($aufgabe, 'n.md', 'x');

    $aufgabe->delete();

    expect(VaultLink::query()->count())->toBe(0)
        ->and(app(Vault::class)->existiert(Bereich::geteilt('firma'), $datei->path))->toBeTrue();
});

it('zieht den Ordner nach, wenn das Objekt umbenannt wird', function () {
    $aufgabe = Aufgabe::query()->create(['titel' => 'Alt']);
    $datei = $this->verknuepfungen->hierAblegen($aufgabe, 'n.md', 'x');

    $aufgabe->update(['titel' => 'Neu']);

    expect($this->verknuepfungen->ordnerNachziehen($aufgabe, 'aufgaben/Alt'))->toBeTrue()
        ->and($datei->fresh()->path)->toBe('aufgaben/Neu/n.md');
});
