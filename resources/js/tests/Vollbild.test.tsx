import { act } from 'react';
import { describe, expect, it } from 'vitest';
import { vaultApi } from '../src/transport';
import { VaultBrowser } from '../src/VaultBrowser';
import { falscherServer, type Mitschrift } from './support/falscherServer';
import { render } from './support/render';

const warten = () => act(async () => { await new Promise((r) => setTimeout(r, 0)); });

/*
 * 0.7.0 (mit aibrain-e1 abgestimmt, Keeper A46 a / A47 a / A50):
 * Grossansicht der Dateiansicht und „Verweise hierher".
 */
function server(rueckverweise: Array<{ id: string; label: string; path: string }> = []) {
    return falscherServer({
        'GET baum': {
            json: {
                bereich: { slug: 'private', label: 'Persönlich', persoenlich: true, darfSchreiben: true },
                baum: [
                    { name: 'ziel.md', path: 'ziel.md', type: 'file' },
                    { name: 'quelle.md', path: 'quelle.md', type: 'file' },
                    { name: 'liste.txt', path: 'liste.txt', type: 'file' },
                ],
            },
        },
        'GET datei': (m: Mitschrift) => ({ json: { path: new URL(m.url, 'http://x').searchParams.get('path'), content: '# x', version: 'v', file_id: null } }),
        'GET verweise': { json: { backlinks: rueckverweise } },
    });
}

function knopf(container: HTMLElement, name: string): HTMLButtonElement | undefined {
    return [...container.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.getAttribute('aria-label') === name);
}

describe('Großansicht', () => {
    it('legt die Datei über alles, zeichnet die Aktionen dort mit und schließt mit Esc', async () => {
        const s = server();
        const api = vaultApi({ basisUrl: '/vault/api', csrfToken: 't', fetch: s.holen });
        const gemeldet: boolean[] = [];
        const aktionen: boolean[] = [];
        const { container, unmount } = render(
            <VaultBrowser
                api={api}
                scope="private"
                startPfad="ziel.md"
                onVollbild={(offen) => gemeldet.push(offen)}
                renderDateiAktionen={({ vollbild }) => {
                    aktionen.push(vollbild);
                    return <span data-assistent />;
                }}
            />,
        );
        await warten();
        expect(container.querySelector('[data-vollbild]')).toBeNull();

        act(() => knopf(container, 'Großansicht')!.click());
        const gross = container.querySelector('[data-vollbild]');
        expect(gross?.getAttribute('role')).toBe('dialog');
        expect(gross?.className).toContain('fixed');
        expect(gross?.querySelector('[data-assistent]')).not.toBeNull();
        expect(aktionen.at(-1)).toBe(true);

        act(() => window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' })));
        expect(container.querySelector('[data-vollbild]')).toBeNull();
        expect(aktionen.at(-1)).toBe(false);
        expect(gemeldet).toEqual([true, false]);
        unmount();
    });

    it('öffnet über das Auge im Baum direkt groß', async () => {
        const s = server();
        const api = vaultApi({ basisUrl: '/vault/api', csrfToken: 't', fetch: s.holen });
        const { container, unmount } = render(<VaultBrowser api={api} scope="private" />);
        await warten();

        act(() => knopf(container, 'quelle.md bildschirmfüllend lesen')!.click());
        await warten();
        expect(container.querySelector('[data-vollbild]')?.getAttribute('aria-label')).toBe('Großansicht: quelle.md');
        unmount();
    });

    it('lässt sich abschalten: kein Knopf, kein Auge', async () => {
        const s = server();
        const api = vaultApi({ basisUrl: '/vault/api', csrfToken: 't', fetch: s.holen });
        const { container, unmount } = render(<VaultBrowser api={api} scope="private" startPfad="ziel.md" vollbild={false} />);
        await warten();

        expect(knopf(container, 'Großansicht')).toBeUndefined();
        expect(knopf(container, 'quelle.md bildschirmfüllend lesen')).toBeUndefined();
        unmount();
    });
});

describe('Verweise hierher', () => {
    const treffer = [{ id: 'quelle.md', label: 'quelle', path: 'quelle.md' }];

    it('zeigt unter einer Notiz, wer auf sie verweist, und öffnet den Verweis', async () => {
        const s = server(treffer);
        const api = vaultApi({ basisUrl: '/vault/api', csrfToken: 't', fetch: s.holen });
        const pfade: string[] = [];
        const { container, unmount } = render(<VaultBrowser api={api} scope="private" startPfad="ziel.md" onPfadGewechselt={(p) => pfade.push(p)} />);
        await warten();
        await warten();

        const abschnitt = container.querySelector('[aria-label="Verweise hierher"]');
        expect(abschnitt?.textContent).toContain('quelle');
        expect(s.mitschrift.some((m) => m.url.includes('verweise') && m.url.includes('path=ziel.md'))).toBe(true);

        act(() => abschnitt!.querySelector('button')!.click());
        expect(pfade.at(-1)).toBe('quelle.md');
        unmount();
    });

    it('fragt bei anderen Dateien und abgeschaltet nicht', async () => {
        const s = server(treffer);
        const api = vaultApi({ basisUrl: '/vault/api', csrfToken: 't', fetch: s.holen });
        const a = render(<VaultBrowser api={api} scope="private" startPfad="liste.txt" />);
        await warten();
        await warten();
        const b = render(<VaultBrowser api={api} scope="private" startPfad="ziel.md" rueckverweise={false} />);
        await warten();
        await warten();

        expect(s.mitschrift.filter((m) => m.url.includes('verweise'))).toHaveLength(0);
        expect(a.container.querySelector('[aria-label="Verweise hierher"]')).toBeNull();
        a.unmount();
        b.unmount();
    });

    it('zeigt nichts, wenn niemand verweist', async () => {
        const s = server([]);
        const api = vaultApi({ basisUrl: '/vault/api', csrfToken: 't', fetch: s.holen });
        const { container, unmount } = render(<VaultBrowser api={api} scope="private" startPfad="ziel.md" />);
        await warten();
        await warten();

        expect(container.querySelector('[aria-label="Verweise hierher"]')).toBeNull();
        unmount();
    });
});
