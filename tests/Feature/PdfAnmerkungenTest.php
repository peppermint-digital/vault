<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Peppermint\Vault\Anmerkungen\PdfAnmerkungen;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Events\DateiGespeichert;
use Peppermint\Vault\Models\VaultFile;
use Peppermint\Vault\Models\VaultVersion;
use Peppermint\Vault\Vault;
use Peppermint\Vault\Verweise;

beforeEach(function () {
    // Der PDF-Helfer wird nicht ausgefuehrt: Process::fake antwortet an seiner Stelle.
    $python = tempnam(sys_get_temp_dir(), 'py');
    config(['vault.anmerkungen.python' => $python]);
    Process::fake(['*' => Process::result(output: '{"ok":true}')]);

    $this->vault = app(Vault::class);
    $this->bereich = Bereich::person(1);
    $absolut = $this->vault->ablage()->aufloesen($this->bereich, 'akten/brief.pdf');
    @mkdir(dirname($absolut), 0775, true);
    file_put_contents($absolut, '%PDF-1.4 probe');
});

it('meldet eine PDF-Anmerkung wie jedes Speichern (Ereignis, Kennung, Version)', function () {
    Event::fake([DateiGespeichert::class]);

    app(PdfAnmerkungen::class)->markieren($this->bereich, 'akten/brief.pdf', 1, 'Satz', autor: 'assistant');

    Event::assertDispatched(DateiGespeichert::class, fn ($e) => $e->bereich->scope() === 'user:1'
        && $e->pfad === 'akten/brief.pdf'
        && $e->dateiId !== null
        && $e->autor === 'assistant');
    expect(VaultFile::query()->where('scope', 'user:1')->where('path', 'akten/brief.pdf')->count())->toBe(1)
        ->and(VaultVersion::query()->where('scope', 'user:1')->where('path', 'akten/brief.pdf')->count())->toBe(1);
});

it('traegt ohne Autor "anmerkung" ein, auch beim Entfernen', function () {
    Event::fake([DateiGespeichert::class]);

    app(PdfAnmerkungen::class)->entfernen($this->bereich, 'akten/brief.pdf', 1, 0);

    Event::assertDispatched(DateiGespeichert::class, fn ($e) => $e->autor === 'anmerkung');
});

it('vergisst den Verweis-Cache nach einer Anmerkung', function () {
    $verweise = Mockery::mock($this->vault->verweise())->makePartial();
    $verweise->shouldReceive('vergessen')->once()->with(Mockery::on(fn ($b) => $b->scope() === 'user:1'));
    $this->app->instance(Verweise::class, $verweise);
    $this->app->forgetInstance(Vault::class);

    app(PdfAnmerkungen::class)->kommentieren($this->bereich, 'akten/brief.pdf', 1, 'Notiz');
});
