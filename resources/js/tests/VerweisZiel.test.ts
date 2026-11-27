import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { verweisZiel, verweisZielIn, wikilinkName } from '../src/verweisZiel';

/*
 * 0.7.6 (#10335): Der JS-Port muss Fall fuer Fall dasselbe Ziel liefern wie
 * Verweise::zielFuer in PHP. Dieselbe Datei prueft tests/Feature/VerweisZielTest.php.
 */
const fixture = JSON.parse(readFileSync(resolve(__dirname, '../../../tests/fixtures/verweis-ziele.json'), 'utf8')) as {
    dateien: string[];
    faelle: Array<{ von: string; link: string; ziel: string | null }>;
};

describe('verweisZiel = Verweise::aufloesen', () => {
    it.each(fixture.faelle)('$von → [[$link]] → $ziel', ({ von, link, ziel }) => {
        expect(verweisZielIn(fixture.dateien, von, link)).toBe(ziel);
    });

    it('nimmt auch die Klammerform mit Alias und Abschnitt', () => {
        expect(wikilinkName('[[Glossar|das Glossar]]')).toBe('Glossar');
        expect(wikilinkName('[[Glossar#Begriffe]]')).toBe('Glossar');
        expect(verweisZielIn(fixture.dateien, 'Kundenportal.md', '[[Glossar|hier]]')).toBe('Wissen/Glossar.md');
    });

    it('arbeitet auf dem geladenen Baum', () => {
        const baum = [
            { name: 'Projekte', path: 'Projekte', type: 'folder' as const, children: [{ name: 'Website.md', path: 'Projekte/Website.md', type: 'file' as const }] },
            { name: 'Start.md', path: 'Start.md', type: 'file' as const },
        ];
        expect(verweisZiel(baum, 'Start.md', 'Website')).toBe('Projekte/Website.md');
        expect(verweisZiel(baum, 'Start.md', 'Fehlt')).toBeNull();
    });
});
