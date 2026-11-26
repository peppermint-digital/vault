<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Contracts\Vaultrechte;
use Peppermint\Vault\Models\VaultSpace;
use Peppermint\Vault\Navigation;

/*
 * 0.5.0: EINE Vault-Leiste fuer alle Produkte (Bastian 26.11.: „Müsste das
 * Menü nicht genauso aussehen wie im AI-Brain?"). Die Leiste zeigt Funktionen,
 * die Bereiche stehen als Reiter auf der Seite.
 */

it('liefert Vault, Graph und Freigabelinks in dieser Reihenfolge', function () {
    $eintraege = app(Navigation::class)->eintraege($this->person());

    expect(array_column($eintraege, 'titel'))->toBe(['Vault', 'Graph', 'Freigabelinks'])
        ->and(array_column($eintraege, 'pfad'))->toBe(['/vault', '/vault/graph', '/vault/freigaben'])
        ->and(array_column($eintraege, 'symbol'))->toBe(['FileText', 'Waypoints', 'Link2']);
});

it('nimmt die Pfade des Produkts und laesst einen Eintrag ohne Pfad weg', function () {
    config(['vault.navigation.freigaben' => '/shares', 'vault.navigation.graph' => null]);

    expect(array_column(app(Navigation::class)->eintraege($this->person()), 'pfad'))->toBe(['/vault', '/shares']);
});

it('zeigt ohne Person keine Leiste', function () {
    expect(app(Navigation::class)->eintraege(null))->toBe([])
        ->and(app(Navigation::class)->bereiche(null))->toBe([]);
});

it('nennt die lesbaren Bereiche: persoenlich, Firma, dann Raeume', function () {
    $person = $this->person();
    $fremd = $this->person('Fremd');
    VaultSpace::query()->create(['name' => 'Team', 'slug' => 'team'])->members()->attach($person->id, ['role' => 'viewer']);
    VaultSpace::query()->create(['name' => 'Allgemein', 'slug' => 'allgemein', 'is_global' => true]);
    VaultSpace::query()->create(['name' => 'Geheim', 'slug' => 'geheim'])->members()->attach($fremd->id, ['role' => 'editor']);

    $bereiche = app(Navigation::class)->bereiche($person);

    expect(array_column($bereiche, 'slug'))->toBe(['private', 'allgemein', 'team'])
        ->and(array_column($bereiche, 'label'))->toBe(['Persönlich', 'Allgemein', 'Team'])
        ->and(array_column($bereiche, 'persoenlich'))->toBe([true, false, false])
        ->and(array_column($bereiche, 'art'))->toBe(['persoenlich', 'global', 'raum'])
        ->and(array_column($bereiche, 'darfSchreiben'))->toBe([true, false, false]);
});

it('haelt sich auch bei einer ungeordneten Rechte-Bindung an Reihenfolge und Leserecht', function () {
    // Ein Produkt bindet eigene Vaultrechte: unsortiert und mit einem Bereich,
    // den es zwar listet, aber nicht lesen laesst.
    app()->instance(Vaultrechte::class, new class implements Vaultrechte
    {
        public function istVerwalter(Authenticatable $user): bool
        {
            return false;
        }

        public function darfLesen(Authenticatable $user, Bereich $bereich): bool
        {
            return ! str_ends_with($bereich->schluessel, '/verboten');
        }

        public function darfSchreiben(Authenticatable $user, Bereich $bereich): bool
        {
            return false;
        }

        public function sichtbareBereiche(Authenticatable $user): array
        {
            return [Bereich::raum('team'), Bereich::raum('verboten'), Bereich::global(), Bereich::person((int) $user->getAuthIdentifier())];
        }
    });
    VaultSpace::query()->create(['name' => 'Allgemein', 'slug' => 'allgemein', 'is_global' => true]);
    VaultSpace::query()->create(['name' => 'Team', 'slug' => 'team']);
    VaultSpace::query()->create(['name' => 'Verboten', 'slug' => 'verboten']);
    app()->forgetInstance(Navigation::class);

    expect(array_column(app(Navigation::class)->bereiche($this->person()), 'slug'))->toBe(['private', 'allgemein', 'team']);
});

it('zeigt keine Leiste, wenn die Rechte keinen Bereich nennen, und laesst leere Pfade weg', function () {
    app()->instance(Vaultrechte::class, new class implements Vaultrechte
    {
        public function istVerwalter(Authenticatable $user): bool
        {
            return false;
        }

        public function darfLesen(Authenticatable $user, Bereich $bereich): bool
        {
            return false;
        }

        public function darfSchreiben(Authenticatable $user, Bereich $bereich): bool
        {
            return false;
        }

        public function sichtbareBereiche(Authenticatable $user): array
        {
            return [];
        }
    });
    app()->forgetInstance(Navigation::class);
    expect(app(Navigation::class)->eintraege($this->person()))->toBe([]);

    app()->forgetInstance(Vaultrechte::class);
    app()->forgetInstance(Navigation::class);
    config(['vault.navigation.graph' => '']);
    expect(array_column(app(Navigation::class)->eintraege($this->person()), 'titel'))->toBe(['Vault', 'Freigabelinks']);
});
