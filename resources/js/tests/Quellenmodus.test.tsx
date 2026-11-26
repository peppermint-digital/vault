import { act } from 'react';
import { describe, expect, it } from 'vitest';
import { fuerQuelle, istQuellenScope, vaultApi } from '../src/transport';
import { VaultBrowser } from '../src/VaultBrowser';
import { falscherServer } from './support/falscherServer';
import { render } from './support/render';

const warten = () => act(async () => { await new Promise((r) => setTimeout(r, 0)); });

/*
 * 0.6.0 (Bastian F52 (2)): Der VaultBrowser zeigt externe Quellen
 * (`extern:<slug>`) — Baum ebenenweise ueber `quelle/ebene`, Dateien ueber
 * `quelle/*`, ohne Versionen, Papierkorb, Freigaben und Suche.
 */
describe('Quellen-Modus', () => {
    it('erkennt Quellen am Namen und leitet Datei-Wege auf quelle/* um', async () => {
        expect(istQuellenScope('extern:grafiken')).toBe(true);
        expect(istQuellenScope('allgemein')).toBe(false);

        const s = falscherServer({ 'GET quelle/datei': { json: { path: 'a.txt', content: 'x', version: 'v', file_id: null } } });
        const api = fuerQuelle(vaultApi({ basisUrl: '/vault/api', csrfToken: 't', fetch: s.holen }));
        await api.datei('extern:grafiken', 'a.txt');

        expect(s.mitschrift[0].url).toBe('/vault/api/quelle/datei?scope=extern%3Agrafiken&path=a.txt');
        expect(api.rohUrl('extern:grafiken', 'b.png')).toBe('/vault/api/quelle/roh?scope=extern%3Agrafiken&path=b.png');
    });

    it('laedt den Baum ebenenweise und blendet Papierkorb und Suche aus', async () => {
        const s = falscherServer({
            'GET quelle/ebene': (m) =>
                m.url.includes('path=Projekte')
                    ? { json: { erreichbar: true, eintraege: [{ name: 'Logo.svg', path: 'Projekte/Logo.svg', type: 'file' }] } }
                    : {
                          json: {
                              erreichbar: true,
                              bereich: { slug: 'extern:grafiken', label: 'Grafiken', persoenlich: false, darfSchreiben: false },
                              eintraege: [{ name: 'Projekte', path: 'Projekte', type: 'folder' }],
                          },
                      },
        });
        const api = vaultApi({ basisUrl: '/vault/api', csrfToken: 't', fetch: s.holen });
        globalThis.localStorage?.clear();
        const { container, unmount } = render(<VaultBrowser api={api} scope="extern:grafiken" />);
        await warten();

        expect(container.textContent).toContain('Grafiken');
        expect(container.textContent).toContain('Projekte');
        expect([...container.querySelectorAll('button')].some((b) => b.textContent?.trim() === 'Papierkorb')).toBe(false);
        expect(container.querySelector('input[aria-label="Im Bereich suchen"]')).toBeNull();
        expect(s.mitschrift.every((m) => !m.url.includes('/baum'))).toBe(true);

        const ordner = [...container.querySelectorAll('button')].find((b) => b.textContent?.includes('Projekte'))!;
        act(() => ordner.click());
        await warten();

        expect(s.mitschrift.map((m) => m.url)).toContain('/vault/api/quelle/ebene?scope=extern%3Agrafiken&path=Projekte');
        expect(container.textContent).toContain('Logo.svg');
        unmount();
    });

    it('sagt ehrlich, wenn die Quelle nicht erreichbar ist', async () => {
        const s = falscherServer({ 'GET quelle/ebene': { json: { erreichbar: false, eintraege: [] } } });
        const api = vaultApi({ basisUrl: '/vault/api', csrfToken: 't', fetch: s.holen });
        const { container, unmount } = render(<VaultBrowser api={api} scope="extern:grafiken" />);
        await warten();

        expect(container.querySelector('[role=status]')?.textContent).toContain('nicht erreichbar');
        unmount();
    });
});
