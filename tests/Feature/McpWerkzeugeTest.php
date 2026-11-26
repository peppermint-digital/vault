<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Mcp\VaultAuflisten;
use Peppermint\Vault\Mcp\VaultLesen;
use Peppermint\Vault\Mcp\VaultSchreiben;
use Peppermint\Vault\Mcp\VaultSuchen;
use Peppermint\Vault\Models\VaultVersion;
use Peppermint\Vault\Vault;

/** So leitet ein Produkt ab: Name setzen, Bereich bestimmen. */
function firmenWerkzeug(string $basis): object
{
    $klassen = [
        VaultLesen::class => new class extends VaultLesen
        {
            protected string $name = 'read-firmen-vault';

            protected function bereich(Request $r, Authenticatable $p): Bereich
            {
                return Bereich::global();
            }
        },
        VaultSchreiben::class => new class extends VaultSchreiben
        {
            protected string $name = 'write-firmen-vault';

            protected function bereich(Request $r, Authenticatable $p): Bereich
            {
                return Bereich::global();
            }
        },
        VaultAuflisten::class => new class extends VaultAuflisten
        {
            protected function bereich(Request $r, Authenticatable $p): Bereich
            {
                return Bereich::person((int) $p->getAuthIdentifier());
            }
        },
        VaultSuchen::class => new class extends VaultSuchen
        {
            protected function bereich(Request $r, Authenticatable $p): Bereich
            {
                return Bereich::global();
            }
        },
    ];

    return $klassen[$basis];
}

function aufrufen(object $werkzeug, array $argumente): Response
{
    return app()->call([$werkzeug, 'handle'], ['request' => new Request($argumente)]);
}

function textVon(Response $antwort): string
{
    return (string) $antwort->content();
}

beforeEach(fn () => $this->vault = app(Vault::class));

it('antwortet ohne handelnde Person gar nicht — kein Rueckfall auf einen Bereich', function () {
    $this->vault->schreiben(Bereich::global(), 'info.md', 'geheim');

    $antwort = aufrufen(firmenWerkzeug(VaultLesen::class), ['path' => 'info.md']);

    expect($antwort->isError())->toBeTrue()
        ->and(textVon($antwort))->not->toContain('geheim');
});

it('liest mit Fassung, laesst ohne Schreibrecht nicht schreiben', function () {
    $person = $this->person();
    Auth::setUser($person);
    $this->vault->schreiben(Bereich::global(), 'info.md', 'Hallo');

    $gelesen = aufrufen(firmenWerkzeug(VaultLesen::class), ['path' => 'info.md']);
    $geschrieben = aufrufen(firmenWerkzeug(VaultSchreiben::class), ['path' => 'info.md', 'content' => 'neu']);

    expect(textVon($gelesen))->toContain('Hallo')->toContain(md5('Hallo'))
        ->and($geschrieben->isError())->toBeTrue()
        ->and($this->vault->lesen(Bereich::global(), 'info.md'))->toBe('Hallo');
});

it('schreibt mit Recht, als assistant fuer die Person, und schuetzt fremde Aenderungen', function () {
    $person = $this->person('Anna');
    $person->rechte = ['shared-vault.edit'];
    Auth::setUser($person);
    $this->vault->schreiben(Bereich::global(), 'plan.md', 'Mensch hat geaendert');

    $veraltet = aufrufen(firmenWerkzeug(VaultSchreiben::class), ['path' => 'plan.md', 'content' => 'Agent', 'base_version' => md5('alt')]);
    $passend = aufrufen(firmenWerkzeug(VaultSchreiben::class), ['path' => 'plan.md', 'content' => 'Agent', 'base_version' => md5('Mensch hat geaendert')]);

    expect($veraltet->isError())->toBeTrue()
        ->and($passend->isError())->toBeFalse()
        ->and($this->vault->lesen(Bereich::global(), 'plan.md'))->toBe('Agent')
        ->and(VaultVersion::query()->latest('id')->first()->author)->toBe('assistant fuer Anna');
});

it('meldet Ausbrueche und Fehlendes als Fehler statt als Ausnahme', function () {
    Auth::setUser($this->person());

    expect(aufrufen(firmenWerkzeug(VaultLesen::class), ['path' => '../1/x.md'])->isError())->toBeTrue()
        ->and(textVon(aufrufen(firmenWerkzeug(VaultLesen::class), ['path' => 'fehlt.md'])))->toBe('Nicht gefunden.')
        ->and(aufrufen(firmenWerkzeug(VaultLesen::class), ['path' => 'tabelle.xlsx'])->isError())->toBeTrue();
});

it('listet den eigenen Bereich und sucht im globalen', function () {
    $person = $this->person();
    Auth::setUser($person);
    $this->vault->schreiben(Bereich::person($person->id), 'projekte/a.md', 'x');
    $this->vault->schreiben(Bereich::global(), 'wissen.md', 'Rabattstaffel fuer Kunden');

    expect(textVon(aufrufen(firmenWerkzeug(VaultAuflisten::class), ['path' => 'projekte'])))->toContain('a.md')
        ->and(aufrufen(firmenWerkzeug(VaultAuflisten::class), ['path' => 'gibtsnicht'])->isError())->toBeTrue()
        ->and(textVon(aufrufen(firmenWerkzeug(VaultSuchen::class), ['query' => 'rabatt'])))->toContain('wissen.md');
});

it('traegt den Namen, den das Produkt setzt', function () {
    expect(firmenWerkzeug(VaultLesen::class)->name())->toBe('read-firmen-vault')
        ->and(firmenWerkzeug(VaultSchreiben::class)->name())->toBe('write-firmen-vault');
});
