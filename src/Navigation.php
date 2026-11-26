<?php

namespace Peppermint\Vault;

use Illuminate\Contracts\Auth\Authenticatable;
use Peppermint\Vault\Contracts\Bereichsnamen;
use Peppermint\Vault\Contracts\Vaultrechte;

/**
 * Die Eintraege der Vault-Leiste — EINE Liste fuer alle Produkte (0.5.0,
 * Bastian 26.11.: „Müsste das Menü nicht genauso aussehen wie im AI-Brain?").
 *
 * Die Leiste zeigt Funktionen, keine Bereiche: Welcher Bereich gerade offen
 * ist, waehlt der `Bereichsreiter` oben auf der Seite. So waechst die Leiste
 * nicht mit den Raeumen, und Vault und Graph teilen sich dieselbe Wahl.
 *
 * Wer keinen einzigen Bereich sieht, bekommt keine Leiste.
 */
class Navigation
{
    /** Reihenfolge und Symbole (lucide). Titel sind deutsch, wie in beiden Produkten. */
    private const EINTRAEGE = [
        'vault' => ['titel' => 'Vault', 'symbol' => 'FileText'],
        'graph' => ['titel' => 'Graph', 'symbol' => 'Waypoints'],
        'freigaben' => ['titel' => 'Freigabelinks', 'symbol' => 'Link2'],
    ];

    public function __construct(
        private readonly Vaultrechte $rechte,
        private readonly Bereichsnamen $namen,
    ) {}

    /**
     * @return list<array{schluessel: string, titel: string, pfad: string, symbol: string}>
     */
    public function eintraege(?Authenticatable $person): array
    {
        if ($person === null || $this->rechte->sichtbareBereiche($person) === []) {
            return [];
        }

        $pfade = (array) config('vault.navigation', []);
        $liste = [];

        foreach (self::EINTRAEGE as $schluessel => $eintrag) {
            $pfad = $pfade[$schluessel] ?? null;
            if (! is_string($pfad) || $pfad === '') {
                continue;
            }

            $liste[] = ['schluessel' => $schluessel, 'titel' => $eintrag['titel'], 'pfad' => $pfad, 'symbol' => $eintrag['symbol']];
        }

        return $liste;
    }

    /**
     * Die Bereiche fuer den `Bereichsreiter`: der persoenliche zuerst, dann
     * der Firmen-Bereich, dann die Raeume — nur, was die Person lesen darf.
     * `slug` ist der `scope`-Wert der Anfragen, wie `Bereichswahl::slug()`.
     *
     * @return list<array{slug: string, label: string, persoenlich: bool, art: 'persoenlich'|'global'|'raum'|'quelle', darfSchreiben: bool}>
     */
    public function bereiche(?Authenticatable $person): array
    {
        if ($person === null) {
            return [];
        }

        $liste = [];
        foreach ($this->rechte->sichtbareBereiche($person) as $bereich) {
            if (! $this->rechte->darfLesen($person, $bereich)) {
                continue;
            }

            $name = $this->namen->name($bereich);
            if (! $bereich->istPersoenlich() && $name === null) {
                continue;
            }

            $rang = $bereich->istPersoenlich() ? 0 : ($bereich->istGleich(Bereich::global()) ? 1 : 2);
            $art = $bereich->istPersoenlich() ? 'persoenlich' : ($bereich->istQuelle() ? 'quelle' : ($rang === 1 ? 'global' : 'raum'));
            $liste[] = [$rang, [
                'slug' => $name ?? '',
                'label' => $this->namen->bezeichnung($bereich),
                'persoenlich' => $bereich->istPersoenlich(),
                'art' => $art,
                'darfSchreiben' => $this->rechte->darfSchreiben($person, $bereich),
            ]];
        }

        // Stabil nach Rang: Reihenfolge innerhalb der Raeume bleibt die der Rechte.
        usort($liste, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return array_map(fn (array $paar): array => $paar[1], $liste);
    }
}
