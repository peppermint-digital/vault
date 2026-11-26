<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Bereichsnamen\StandardNamen;
use Peppermint\Vault\Contracts\Bereichsnamen;
use Peppermint\Vault\Contracts\Vaultrechte;
use Peppermint\Vault\Models\VaultSpace;
use Peppermint\Vault\Rechte\Standardrechte;
use Peppermint\Vault\Tests\Fixtures\Aufgabe;
use Peppermint\Vault\Vault;

/*
 * So baut der Manager seine Objekt-Bereiche (V3, W1): Praefix `objekt:` und
 * eigene Rechte, die an die Policy des Objekts uebergeben.
 */
beforeEach(function () {
    Schema::create('aufgaben', function (Blueprint $table) {
        $table->id();
        $table->string('titel');
        $table->unsignedBigInteger('besitzer');
        $table->timestamps();
    });

    app(StandardNamen::class)->praefix(
        'objekt:',
        aus: function (string $rest, Authenticatable $p): ?Bereich {
            [$art, $id] = array_pad(explode(':', $rest, 2), 2, '');

            return $art === 'aufgabe' && ctype_digit($id) ? Bereich::geteilt("objekte/aufgabe/{$id}") : null;
        },
        name: fn (Bereich $b): ?string => preg_match('#^objekte/aufgabe/(\d+)$#', $b->schluessel, $m) ? 'aufgabe:'.$m[1] : null,
        bezeichnung: fn (Bereich $b): ?string => 'Aufgabe '.substr($b->schluessel, 16),
    );

    Gate::define('view', fn ($user, Aufgabe $a) => (int) $a->besitzer === (int) $user->getAuthIdentifier());

    app()->instance(Vaultrechte::class, new class extends Standardrechte
    {
        public function darfLesen(Authenticatable $user, Bereich $bereich): bool
        {
            if (preg_match('#^objekte/aufgabe/(\d+)$#', $bereich->schluessel, $m)) {
                $aufgabe = Aufgabe::query()->find($m[1]);

                return $aufgabe !== null && Gate::forUser($user)->allows('view', $aufgabe);
            }

            return parent::darfLesen($user, $bereich);
        }

        public function darfSchreiben(Authenticatable $user, Bereich $bereich): bool
        {
            return str_starts_with($bereich->schluessel, 'objekte/') ? $this->darfLesen($user, $bereich) : parent::darfSchreiben($user, $bereich);
        }
    });
});

it('spricht einen Objekt-Bereich ueber ein angemeldetes Praefix an — mit den Rechten des Objekts', function () {
    $anna = $this->person('Anna');
    $bert = $this->person('Bert');
    $aufgabe = Aufgabe::query()->create(['titel' => 'A', 'besitzer' => $anna->id]);
    app(Vault::class)->schreiben(Bereich::geteilt("objekte/aufgabe/{$aufgabe->id}"), 'Notizen/n.md', 'nur fuer Anna');

    $this->actingAs($anna)->getJson("/vault/api/baum?scope=objekt:aufgabe:{$aufgabe->id}")
        ->assertOk()
        ->assertJsonPath('bereich.slug', "objekt:aufgabe:{$aufgabe->id}")
        ->assertJsonPath('bereich.label', "Aufgabe {$aufgabe->id}")
        ->assertJsonPath('baum.0.children.0.path', 'Notizen/n.md');

    $this->actingAs($bert)->getJson("/vault/api/datei?scope=objekt:aufgabe:{$aufgabe->id}&path=Notizen/n.md")->assertNotFound();
});

it('antwortet auf unbekannte Namen mit 404', function () {
    $person = $this->person();

    $this->actingAs($person)->getJson('/vault/api/baum?scope=objekt:projekt:1')->assertNotFound();
    $this->actingAs($person)->getJson('/vault/api/baum?scope=objekt:aufgabe:999')->assertNotFound();
    $this->actingAs($person)->getJson('/vault/api/baum?scope=kein-raum')->assertNotFound();
});

it('laesst keinen Raum ein angemeldetes Praefix ueberdecken und keine eingebauten ueberschreiben', function () {
    VaultSpace::query()->create(['name' => 'Raum', 'slug' => 'objekt']);

    expect(app(Bereichsnamen::class)->aus('objekt:aufgabe:abc', $this->person()))->toBeNull()
        ->and(fn () => app(StandardNamen::class)->praefix('extern:', fn () => null, fn () => null))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(StandardNamen::class)->praefix('ohne', fn () => null, fn () => null))->toThrow(InvalidArgumentException::class);
});

it('nennt die eingebauten Bereiche wie bisher', function () {
    VaultSpace::query()->create(['name' => 'Allgemein', 'slug' => 'allgemein', 'is_global' => true]);
    VaultSpace::query()->create(['name' => 'Team', 'slug' => 'team']);
    $namen = app(Bereichsnamen::class);
    $person = $this->person();

    expect($namen->name(Bereich::person(3)))->toBe('private')
        ->and($namen->name(Bereich::global()))->toBe('allgemein')
        ->and($namen->name(Bereich::raum('team')))->toBe('team')
        ->and($namen->bezeichnung(Bereich::raum('team')))->toBe('Team')
        ->and($namen->aus('', $person)->scope())->toBe('user:'.$person->id)
        ->and($namen->aus('extern:x', $person))->toBeNull();
});
