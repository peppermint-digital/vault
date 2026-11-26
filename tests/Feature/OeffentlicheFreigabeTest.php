<?php

use Illuminate\Support\Facades\Hash;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Models\VaultShare;
use Peppermint\Vault\Vault;

beforeEach(function () {
    $this->vault = app(Vault::class);
    $this->person = $this->person('Anna');
    $this->bereich = Bereich::person($this->person->id);
});

function freigabe(int $userId, string $pfad, array $mehr = []): VaultShare
{
    return VaultShare::query()->create(['user_id' => $userId, 'vault_path' => $pfad] + $mehr);
}

it('zeigt eine Notiz ohne Anmeldung und zaehlt den Aufruf', function () {
    $this->vault->schreiben($this->bereich, 'notizen/plan.md', '# Plan');
    $link = freigabe($this->person->id, 'notizen/plan.md');

    $this->getJson("/vault/geteilt/{$link->token}/info")
        ->assertOk()
        ->assertJsonPath('content', '# Plan')
        ->assertJsonPath('gesperrt', false)
        ->assertJsonPath('sharedBy', 'Anna');

    expect($link->fresh()->view_count)->toBe(1);
});

it('antwortet 404 auf Unbekanntes und 410 auf Zurueckgezogenes oder Abgelaufenes', function () {
    $this->vault->schreiben($this->bereich, 'a.md', 'x');
    $weg = freigabe($this->person->id, 'a.md', ['revoked_at' => now()]);
    $alt = freigabe($this->person->id, 'a.md', ['expires_at' => now()->subDay()]);

    $this->getJson('/vault/geteilt/gibtsnicht/info')->assertNotFound();
    $this->getJson("/vault/geteilt/{$weg->token}/info")->assertStatus(410);
    $this->getJson("/vault/geteilt/{$alt->token}/roh")->assertStatus(410);
});

it('haelt den Inhalt hinter dem Passwort, bis es stimmt', function () {
    $this->vault->schreiben($this->bereich, 'a.md', 'geheim');
    $link = freigabe($this->person->id, 'a.md', ['password_hash' => Hash::make('richtig')]);

    $this->getJson("/vault/geteilt/{$link->token}/info")->assertOk()->assertJsonPath('gesperrt', true)->assertJsonPath('content', null);
    $this->get("/vault/geteilt/{$link->token}/roh")->assertForbidden();
    $this->postJson("/vault/geteilt/{$link->token}/entsperren", ['password' => 'falsch'])->assertStatus(422);

    $this->postJson("/vault/geteilt/{$link->token}/entsperren", ['password' => 'richtig'])->assertOk();

    $this->getJson("/vault/geteilt/{$link->token}/info")->assertJsonPath('content', 'geheim');
    expect($link->fresh()->view_count)->toBe(1);
});

it('liefert Nachbardateien aus dem Ordner der Freigabe, aber nichts darueber hinaus', function () {
    $this->vault->schreiben($this->bereich, 'seite/index.html', '<html><head></head><body><img src="bild.png"></body></html>');
    $this->vault->schreiben($this->bereich, 'seite/bild.json', '{}');
    $this->vault->schreiben($this->bereich, 'privat.md', 'nicht fuer dich');
    $link = freigabe($this->person->id, 'seite/index.html');

    $seite = $this->get("/vault/geteilt/{$link->token}/roh")->assertOk();
    expect($seite->getContent())->toContain('<base href="/vault/geteilt/'.$link->token.'/roh/">')
        ->and($seite->headers->get('Content-Security-Policy'))->toContain("default-src 'none'");

    $this->get("/vault/geteilt/{$link->token}/roh/bild.json")->assertOk();
    $this->get("/vault/geteilt/{$link->token}/roh/../privat.md")->assertNotFound();
    $this->get("/vault/geteilt/{$link->token}/roh/..%2Fprivat.md")->assertNotFound();
});

it('gibt einer Freigabe in der Wurzel keine Nachbarn', function () {
    $this->vault->schreiben($this->bereich, 'oben.md', 'x');
    $this->vault->schreiben($this->bereich, 'anderes.md', 'nicht fuer dich');
    $link = freigabe($this->person->id, 'oben.md');

    $this->get("/vault/geteilt/{$link->token}/roh")->assertOk();
    $this->get("/vault/geteilt/{$link->token}/roh/anderes.md")->assertNotFound();
});

it('liest Freigaben aus einem geteilten Bereich aus diesem Bereich', function () {
    $this->vault->schreiben(Bereich::global(), 'info.md', 'fuer alle');
    $link = freigabe($this->person->id, 'info.md', ['area_key' => '_shared']);

    $this->getJson("/vault/geteilt/{$link->token}/info")->assertJsonPath('content', 'fuer alle');
});
