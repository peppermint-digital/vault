<?php

namespace Peppermint\Vault;

use Illuminate\Database\Eloquent\Model;
use Peppermint\Vault\Contracts\Ablageregel;

/**
 * Das Verzeichnis der Ablage-Regeln: je Objektart (Morph-Kuerzel) eine.
 *
 * Das Produkt meldet sie im eigenen ServiceProvider an. Das Paket bringt
 * keine mit — welche Objekte es gibt, weiss nur das Produkt.
 */
class Ablageregeln
{
    /** @var array<string, Ablageregel> */
    private array $regeln = [];

    public function anmelden(string $morphKuerzel, Ablageregel $regel): void
    {
        $this->regeln[$morphKuerzel] = $regel;
    }

    public function hat(Model $objekt): bool
    {
        return isset($this->regeln[$objekt->getMorphClass()]);
    }

    public function fuer(Model $objekt): ?Ablageregel
    {
        return $this->regeln[$objekt->getMorphClass()] ?? null;
    }

    /** @return array<string, Ablageregel> */
    public function alle(): array
    {
        return $this->regeln;
    }
}
