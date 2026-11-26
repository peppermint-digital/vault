<?php

namespace Peppermint\Vault\Bereichsnamen;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Contracts\Bereichsnamen;
use Peppermint\Vault\Models\VaultSource;
use Peppermint\Vault\Models\VaultSpace;

/**
 * Die Vorgabe fuer `?scope=` (bisher fest in `Bereichswahl`):
 *
 * - leer oder `private` → der persoenliche Bereich der anfragenden Person;
 * - `extern:<slug>` → eine externe Quelle (nur mit `vault.quellen`);
 * - ein angemeldetes Praefix (`objekt:` …) → was das Produkt dafuer liefert;
 * - sonst der Slug eines Raums (der globale Raum steht fuer den globalen Bereich).
 *
 * Praefixe stehen VOR dem Raum-Slug, damit kein Raum einen angemeldeten Namen
 * ueberdecken kann. Ein Praefix endet deshalb immer mit `:` — Raum-Slugs
 * enthalten keinen Doppelpunkt.
 */
class StandardNamen implements Bereichsnamen
{
    /** @var array<string, array{aus: Closure(string, Authenticatable): ?Bereich, name: Closure(Bereich): ?string, bezeichnung: Closure(Bereich): ?string}> */
    private array $praefixe = [];

    /**
     * Weiteres Praefix anmelden, z.B. `objekt:`.
     *
     * @param  Closure(string, Authenticatable): ?Bereich  $aus  bekommt den Rest nach dem Praefix
     * @param  Closure(Bereich): ?string  $name  Rest-Name fuer einen Bereich, null = nicht meiner
     * @param  Closure(Bereich): ?string|null  $bezeichnung
     */
    public function praefix(string $praefix, Closure $aus, Closure $name, ?Closure $bezeichnung = null): void
    {
        if (! str_ends_with($praefix, ':') || in_array($praefix, ['extern:'], true)) {
            throw new InvalidArgumentException('Ein Praefix endet mit „:" und darf kein eingebautes sein.');
        }

        $this->praefixe[$praefix] = ['aus' => $aus, 'name' => $name, 'bezeichnung' => $bezeichnung ?? fn (): ?string => null];
    }

    public function aus(string $name, Authenticatable $person): ?Bereich
    {
        $name = trim($name);

        if ($name === '' || $name === 'private') {
            return Bereich::person((int) $person->getAuthIdentifier());
        }

        if (str_starts_with($name, 'extern:')) {
            return $this->quelle(substr($name, 7));
        }

        foreach ($this->praefixe as $praefix => $p) {
            if (str_starts_with($name, $praefix)) {
                return ($p['aus'])(substr($name, strlen($praefix)), $person);
            }
        }

        return VaultSpace::query()->where('slug', $name)->first()?->bereich();
    }

    public function name(Bereich $bereich): ?string
    {
        if ($bereich->istPersoenlich()) {
            return 'private';
        }

        if ($bereich->istQuelle()) {
            return 'extern:'.$bereich->schluessel;
        }

        foreach ($this->praefixe as $praefix => $p) {
            $rest = ($p['name'])($bereich);
            if ($rest !== null) {
                return $praefix.$rest;
            }
        }

        return $this->raum($bereich)?->slug;
    }

    public function bezeichnung(Bereich $bereich): string
    {
        if ($bereich->istPersoenlich()) {
            // Ueberschreibbar, weil jedes Produkt den Bereich anders nennt (Manager: „Mein Arbeits-Vault").
            return (string) config('vault.bezeichnungen.persoenlich', 'Persönlich');
        }

        if ($bereich->istQuelle()) {
            return (string) (VaultSource::query()->where('slug', $bereich->schluessel)->value('name') ?? $bereich->schluessel);
        }

        foreach ($this->praefixe as $p) {
            if (($p['name'])($bereich) !== null) {
                return ($p['bezeichnung'])($bereich) ?? $bereich->schluessel;
            }
        }

        return $this->raum($bereich)?->name ?? $bereich->schluessel;
    }

    private function quelle(string $slug): ?Bereich
    {
        if (! config('vault.quellen', false)) {
            return null;
        }

        try {
            return Bereich::quelle($slug);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function raum(Bereich $bereich): ?VaultSpace
    {
        if ($bereich->istGleich(Bereich::global())) {
            return VaultSpace::query()->where('is_global', true)->first();
        }

        return VaultSpace::query()->where('is_global', false)->get()->first(fn (VaultSpace $r) => $r->bereich()->istGleich($bereich));
    }
}
