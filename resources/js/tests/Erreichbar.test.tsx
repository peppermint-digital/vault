import { describe, expect, it } from 'vitest';
import { Bereichsreiter } from '../src/Bereichsreiter';
import { render } from './support/render';

/*
 * 0.6.4 (aibrain-e1 F52b): Eine nicht erreichbare Quelle traegt im Reiter
 * ein Warndreieck — wie der alte Reiter in AI Brain.
 */
describe('Bereichsreiter mit Quellen', () => {
    const bereiche = [
        { slug: 'private', label: 'Persönlich', art: 'persoenlich' as const, darfSchreiben: true },
        { slug: 'extern:da', label: 'Grafik', art: 'quelle' as const, darfSchreiben: true, erreichbar: true },
        { slug: 'extern:weg', label: 'Archiv', art: 'quelle' as const, darfSchreiben: false, erreichbar: false },
    ];

    it('warnt nur bei der nicht erreichbaren Quelle', () => {
        const { container, unmount } = render(<Bereichsreiter bereiche={bereiche} aktiv="extern:weg" onWechsel={() => {}} />);
        const knoepfe = [...container.querySelectorAll<HTMLButtonElement>('[role=group] button')];

        expect(knoepfe.map((k) => k.title)).toEqual(['', '', 'Gerade nicht erreichbar']);
        expect(knoepfe.map((k) => k.querySelector('[aria-label="nicht erreichbar"]') !== null)).toEqual([false, false, true]);
        expect(container.textContent).toContain('gerade nicht erreichbar');
        unmount();
    });

    it('zeigt eine erreichbare Quelle wie bisher', () => {
        const { container, unmount } = render(<Bereichsreiter bereiche={bereiche} aktiv="extern:da" onWechsel={() => {}} />);

        expect(container.textContent).toContain('Extern angebundene Quelle — keine Versionen');
        expect(container.textContent).not.toContain('nicht erreichbar');
        unmount();
    });
});
