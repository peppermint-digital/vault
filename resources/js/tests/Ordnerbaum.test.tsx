import { beforeEach, describe, expect, it } from 'vitest';
import { Ordnerbaum } from '../src/Ordnerbaum';
import type { Baumeintrag } from '../src/typen';
import { click, render, textOf } from './support/render';

const baum: Baumeintrag[] = [{ name: 'Projekte', path: 'Projekte', type: 'folder', children: [{ name: 'a.md', path: 'Projekte/a.md', type: 'file' }] }];

beforeEach(() => localStorage.clear());

describe('Ordnerbaum', () => {
    it('merkt sich aufgeklappte Ordner je Bereich getrennt', () => {
        const erster = render(<Ordnerbaum baum={baum} aktuellerPfad="" speicherSchluessel="private" onWaehlen={() => {}} />);
        click(erster.container.querySelector('button[aria-expanded]')!);
        expect(textOf(erster.container)).toContain('a.md');
        erster.unmount();

        const gleicherBereich = render(<Ordnerbaum baum={baum} aktuellerPfad="" speicherSchluessel="private" onWaehlen={() => {}} />);
        expect(textOf(gleicherBereich.container)).toContain('a.md');
        gleicherBereich.unmount();

        const andererBereich = render(<Ordnerbaum baum={baum} aktuellerPfad="" speicherSchluessel="team" onWaehlen={() => {}} />);
        expect(textOf(andererBereich.container)).not.toContain('a.md');
        andererBereich.unmount();
    });

    it('klappt den Ordner der geoeffneten Datei auf', () => {
        const { container, unmount } = render(<Ordnerbaum baum={baum} aktuellerPfad="Projekte/a.md" speicherSchluessel="x" onWaehlen={() => {}} />);

        expect(textOf(container)).toContain('a.md');
        unmount();
    });

    it('zeigt ohne Schreibrecht keine aendernden Aktionen', () => {
        const { container, unmount } = render(<Ordnerbaum baum={baum} aktuellerPfad="Projekte/a.md" speicherSchluessel="x" nurLesen onWaehlen={() => {}} />);

        expect(container.querySelector('[aria-label^="Aktionen"]')).toBeNull();
        unmount();
    });

    it('waehlt eine Datei per Klick', () => {
        let gewaehlt = '';
        const { container, unmount } = render(<Ordnerbaum baum={baum} aktuellerPfad="Projekte/a.md" speicherSchluessel="x" onWaehlen={(p) => (gewaehlt = p)} />);

        click([...container.querySelectorAll('button')].find((b) => b.textContent === 'a.md')!);

        expect(gewaehlt).toBe('Projekte/a.md');
        unmount();
    });
});

describe('Ordnerbaum mit Vorschau', () => {
    it('zeigt fuer Bilder ein Vorschaubild statt des Symbols', () => {
        const bilder: Baumeintrag[] = [{ name: 'foto.png', path: 'foto.png', type: 'file' }, { name: 'n.md', path: 'n.md', type: 'file' }];
        const { container, unmount } = render(
            <Ordnerbaum baum={bilder} aktuellerPfad="" speicherSchluessel="v" onWaehlen={() => {}} vorschauUrl={(p) => (p.endsWith('.png') ? `/v/${p}` : null)} />,
        );

        const img = container.querySelectorAll('img');
        expect(img).toHaveLength(1);
        expect(img[0].getAttribute('src')).toBe('/v/foto.png');
        expect(img[0].getAttribute('loading')).toBe('lazy');
        unmount();
    });
});
