import { act } from 'react';
import { describe, expect, it } from 'vitest';
import { Bereichsreiter } from '../src/Bereichsreiter';
import { vaultApi } from '../src/transport';
import { VerweisGraph } from '../src/VerweisGraph';
import { falscherServer } from './support/falscherServer';
import { render } from './support/render';

const warten = () => act(async () => { await new Promise((r) => setTimeout(r, 0)); });

describe('Bereichsreiter', () => {
    const bereiche = [
        { slug: 'private', label: 'Persönlich', art: 'persoenlich' as const, darfSchreiben: true },
        { slug: 'firma', label: 'Firmen-Vault', art: 'global' as const, darfSchreiben: false },
    ];

    it('hebt den offenen Bereich hervor, nennt sein Recht und meldet nur einen echten Wechsel', () => {
        const gewechselt: string[] = [];
        const { container, unmount } = render(<Bereichsreiter bereiche={bereiche} aktiv="firma" onWechsel={(s) => gewechselt.push(s)} />);
        const knoepfe = [...container.querySelectorAll<HTMLButtonElement>('[role=group] button')];

        expect(knoepfe.map((k) => k.getAttribute('aria-pressed'))).toEqual(['false', 'true']);
        expect(container.textContent).toContain('Offener Bereich — alle lesen. Nur Lesen.');
        act(() => knoepfe[1].click());
        act(() => knoepfe[0].click());

        expect(gewechselt).toEqual(['private']);
        unmount();
    });

    it('zeigt Eigenes des Produkts rechts daneben', () => {
        const { container, unmount } = render(
            <Bereichsreiter bereiche={bereiche} aktiv="private" onWechsel={() => {}}>
                <span data-probe>Neuer Raum</span>
            </Bereichsreiter>,
        );
        expect(container.querySelector('[data-probe]')).not.toBeNull();
        expect(container.textContent).toContain('nur du siehst und bearbeitest ihn');
        unmount();
    });
});

describe('VerweisGraph', () => {
    it('fragt den Graph genau des gewaehlten Bereichs an', async () => {
        const s = falscherServer({ 'GET verweise': { json: { nodes: [], edges: [] } } });
        const api = vaultApi({ basisUrl: '/vault/api', csrfToken: 't', fetch: s.holen });
        const { container, unmount } = render(<VerweisGraph api={api} scope="firma" onOeffnen={() => {}} />);
        await warten();

        expect(s.mitschrift.map((m) => m.url)).toEqual(['/vault/api/verweise?scope=firma']);
        expect(container.textContent).toContain('noch keine Verweise');
        unmount();
    });

    it('zeigt eine Absage des Servers, statt leer zu bleiben', async () => {
        const s = falscherServer({ 'GET verweise': { status: 404, json: { message: 'Nicht gefunden.' } } });
        const api = vaultApi({ basisUrl: '/vault/api', csrfToken: 't', fetch: s.holen });
        const { container, unmount } = render(<VerweisGraph api={api} scope="fremd" onOeffnen={() => {}} />);
        await warten();

        expect(container.querySelector('[role=alert]')).not.toBeNull();
        unmount();
    });

    it('zeichnet Knoten und oeffnet beim Klick die Datei', async () => {
        const s = falscherServer({
            'GET verweise': {
                json: {
                    nodes: [
                        { id: 'a.md', label: 'a', path: 'a.md', linkCount: 0 },
                        { id: 'b.md', label: 'b', path: 'b.md', linkCount: 1 },
                    ],
                    edges: [{ source: 'a.md', target: 'b.md' }],
                },
            },
        });
        const api = vaultApi({ basisUrl: '/vault/api', csrfToken: 't', fetch: s.holen });
        const geoeffnet: string[] = [];
        const { container, unmount } = render(<VerweisGraph api={api} scope="firma" onOeffnen={(p) => geoeffnet.push(p)} />);
        await warten();

        const knoten = container.querySelectorAll('.nodes g');
        expect(knoten.length).toBe(2);
        expect(container.querySelector('svg.h-full')!.querySelectorAll('line').length).toBe(1);
        act(() => {
            knoten[1].dispatchEvent(new MouseEvent('click', { bubbles: true }));
        });
        expect(geoeffnet).toEqual(['b.md']);
        unmount();
    });
});
