import { act } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { vaultApi } from '../src/transport';
import { VaultBrowser } from '../src/VaultBrowser';
import { falscherServer, type Mitschrift } from './support/falscherServer';
import { render } from './support/render';

const warten = () => act(async () => { await new Promise((r) => setTimeout(r, 0)); });

afterEach(() => vi.restoreAllMocks());

/*
 * 0.7.2 (aibrain-e1, F52c2-DevStack):
 * 1. Dateiansicht und Rueckverweise hatten denselben key -> React liess alte
 *    Ansichten stehen; nach drei Dateiwechseln vier Fassungen untereinander.
 * 2. renderPdf wurde in Quellen verworfen (seit 0.6.0) - das Produkt
 *    entscheidet, was es dort zeigt.
 */
describe('Dateiwechsel mit Rückverweisen', () => {
    it('zeigt nach mehreren Wechseln genau eine Dateiansicht, ohne doppelte Schlüssel', async () => {
        const fehler = vi.spyOn(console, 'error').mockImplementation(() => {});
        const s = falscherServer({
            'GET baum': {
                json: {
                    bereich: { slug: 'private', label: 'Persönlich', persoenlich: true, darfSchreiben: true },
                    baum: ['a.md', 'b.md', 'c.md'].map((n) => ({ name: n, path: n, type: 'file' })),
                },
            },
            'GET datei': (m: Mitschrift) => {
                const p = new URL(m.url, 'http://x').searchParams.get('path');
                return { json: { path: p, content: `# ${p}`, version: 'v', file_id: null } };
            },
            'GET verweise': { json: { backlinks: [{ id: 'x.md', label: 'x', path: 'x.md' }] } },
        });
        const api = vaultApi({ basisUrl: '/vault/api', fetch: s.holen });
        const { container, unmount } = render(<VaultBrowser api={api} scope="private" startPfad="a.md" />);
        await warten();
        await warten();

        for (const name of ['b.md', 'c.md', 'a.md']) {
            act(() => [...container.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.trim() === name)!.click());
            await warten();
            await warten();
        }

        expect(container.querySelectorAll('h2.text-lg').length).toBe(1);
        expect(container.querySelector('h2.text-lg')?.textContent).toBe('a.md');
        expect(container.querySelectorAll('[aria-label="Verweise hierher"]').length).toBe(1);
        expect(fehler.mock.calls.some((c) => String(c[0]).includes('same key'))).toBe(false);
        unmount();
    });
});

describe('PDF in Quellen', () => {
    it('reicht den PDF-Slot des Produkts auch in Quellen durch', async () => {
        const s = falscherServer({
            'GET quelle/ebene': {
                json: {
                    erreichbar: true,
                    bereich: { slug: 'extern:grafiken', label: 'Grafiken', persoenlich: false, darfSchreiben: false },
                    eintraege: [{ name: 'plan.pdf', path: 'plan.pdf', type: 'file' }],
                },
            },
        });
        const api = vaultApi({ basisUrl: '/vault/api', fetch: s.holen });
        const slots: string[] = [];
        const { container, unmount } = render(
            <VaultBrowser
                api={api}
                scope="extern:grafiken"
                startPfad="plan.pdf"
                renderPdf={({ scope, pfad }) => {
                    slots.push(`${scope}|${pfad}`);
                    return <iframe data-eigener-betrachter title="pdf" />;
                }}
            />,
        );
        await warten();
        await warten();

        expect(container.querySelector('[data-eigener-betrachter]')).not.toBeNull();
        expect(slots.at(-1)).toBe('extern:grafiken|plan.pdf');
        unmount();
    });
});
