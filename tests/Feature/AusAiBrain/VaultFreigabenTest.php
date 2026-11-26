<?php

/* Aus AI Brain tests/Feature/NoteShareTest.php (Stand 118ee2b2). */

use Peppermint\Vault\Bereich;
use Peppermint\Vault\Models\VaultShare;
use Peppermint\Vault\Vault;

/** Nicht-Textdateien (svg, css) gehen nicht ueber den Textweg — direkt auf die Platte. */
function ablegen(Vault $vault, Bereich $bereich, string $pfad, string $inhalt): void
{
    $absolut = $vault->absolut($bereich, $pfad);
    @mkdir(dirname($absolut), 0755, true);
    file_put_contents($absolut, $inhalt);
}

beforeEach(function () {
    $this->vault = app(Vault::class);
    $this->person = $this->person('Anna');
    $this->bereich = Bereich::person($this->person->id);
});

it('erzeugt URL-sichere Tokens mit viel Zufall und vergibt sie beim Anlegen selbst', function () {
    $token = VaultShare::generateToken();
    $link = VaultShare::query()->create(['user_id' => $this->person->id, 'vault_path' => 'a.md']);

    expect($token)->toMatch('/^[A-Za-z0-9_-]{32}$/')
        ->and($link->token)->toMatch('/^[A-Za-z0-9_-]{32}$/')
        ->and(VaultShare::generateToken())->not->toBe($token);
});

it('lehnt Freigaben auf Pfade ausserhalb des Bereichs und auf fehlende Dateien ab', function () {
    $this->actingAs($this->person)->postJson('/vault/api/freigaben', ['path' => '../2/x.md'])->assertForbidden();
    $this->actingAs($this->person)->postJson('/vault/api/freigaben', ['path' => 'fehlt.md'])->assertNotFound();
});

it('speichert das Passwort nur als Hash und setzt den Ablauf in die Zukunft', function () {
    $this->vault->schreiben($this->bereich, 'a.md', 'x');

    $this->actingAs($this->person)->postJson('/vault/api/freigaben', ['path' => 'a.md', 'password' => 'geheim1', 'expires_in_days' => 7])->assertCreated();

    $link = VaultShare::query()->first();
    expect($link->password_hash)->not->toBe('geheim1')->not->toBeNull()
        ->and($link->expires_at->isFuture())->toBeTrue()
        ->and($link->toArray())->not->toHaveKey('password_hash');
});

it('liefert Unterordner der Freigabe, aber keine Ordner, keine fehlenden Dateien und keine Ausbrueche', function () {
    $this->vault->schreiben($this->bereich, 'seite/index.html', '<p>ohne head</p>');
    ablegen($this->vault, $this->bereich, 'seite/bilder/a.svg', '<svg/>');
    $link = VaultShare::query()->create(['user_id' => $this->person->id, 'vault_path' => 'seite/index.html']);
    $t = $link->token;

    $this->get("/vault/geteilt/{$t}/roh/bilder/a.svg")->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    $this->get("/vault/geteilt/{$t}/roh/bilder")->assertNotFound();
    $this->get("/vault/geteilt/{$t}/roh/fehlt.png")->assertNotFound();
    $this->get("/vault/geteilt/{$t}/roh//etc/passwd")->assertNotFound();
    $this->get("/vault/geteilt/{$t}/roh/a%00.svg")->assertNotFound();
    expect($this->get("/vault/geteilt/{$t}/roh")->getContent())->toBe('<p>ohne head</p>');
});

it('zaehlt Aufrufe von Nachbardateien nicht mit', function () {
    $this->vault->schreiben($this->bereich, 'seite/index.html', '<p>x</p>');
    ablegen($this->vault, $this->bereich, 'seite/stil.css', 'p{}');
    $link = VaultShare::query()->create(['user_id' => $this->person->id, 'vault_path' => 'seite/index.html']);

    $this->get("/vault/geteilt/{$link->token}/roh/stil.css")->assertOk();
    $this->get("/vault/geteilt/{$link->token}/roh")->assertOk();

    expect($link->fresh()->view_count)->toBe(0);
});

it('meldet HTML-Freigaben als format=html', function () {
    $this->vault->schreiben($this->bereich, 'seite.html', '<p>x</p>');
    $link = VaultShare::query()->create(['user_id' => $this->person->id, 'vault_path' => 'seite.html']);

    $this->getJson("/vault/geteilt/{$link->token}/info")->assertJsonPath('format', 'html')->assertJsonPath('content', null);
});
