import { act } from 'react';
import { describe, expect, it } from 'vitest';
import { Dateiansicht } from '../src/Dateiansicht';
import { vaultApi } from '../src/transport';
import { falscherServer } from './support/falscherServer';
import { click, render, textOf } from './support/render';

const warten = () => act(async () => { await new Promise((r) => setTimeout(r, 0)); });

function knopf(container: HTMLElement, text: string): HTMLButtonElement {
    const b = [...container.querySelectorAll('button')].find((x) => x.textContent?.includes(text));
    if (!b) throw new Error(`Kein Knopf „${text}" in: ${textOf(container)}`);
    return b as HTMLButtonElement;
}

function tippen(feld: HTMLTextAreaElement, wert: string) {
    act(() => {
        const setter = Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value')!.set!;
        setter.call(feld, wert);
        feld.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

describe('Dateiansicht', () => {
    it('fragt bei einem Konflikt nach und speichert erst auf Wunsch mit force', async () => {
        let patches = 0;
        const s = falscherServer({
            'GET datei': { json: { path: 'a.md', content: 'alt', version: 'v1', file_id: '01' } },
            'PATCH datei': (m) => {
                patches++;
                const r = m.rumpf as { force?: boolean };
                return r.force ? { json: { saved: true, version: 'v3' } } : { status: 409, json: { conflict: true, current_content: 'fremd', current_version: 'v2' } };
            },
        });
        const api = vaultApi({ basisUrl: '/vault/api', csrfToken: 't', fetch: s.holen });
        const { container, unmount } = render(<Dateiansicht api={api} scope="private" pfad="a.md" darfSchreiben />);
        await warten();

        click(knopf(container, 'Bearbeiten'));
        tippen(container.querySelector('textarea')!, 'meins');
        click(knopf(container, 'Speichern'));
        await warten();

        expect(container.querySelector('[role=alert]')?.textContent).toContain('geändert');
        expect(container.querySelector('textarea')?.value).toBe('meins');

        click(knopf(container, 'trotzdem speichern'));
        await warten();

        expect(patches).toBe(2);
        expect(s.mitschrift.at(-1)?.rumpf).toMatchObject({ content: 'meins', base_version: 'v1', force: true });
        expect(textOf(container)).toContain('Gespeichert.');
        unmount();
    });

    it('bietet ohne Schreibrecht kein Bearbeiten an', async () => {
        const s = falscherServer({ 'GET datei': { json: { path: 'a.md', content: 'nur lesen', version: 'v1', file_id: null } } });
        const api = vaultApi({ basisUrl: '/vault/api', csrfToken: 't', fetch: s.holen });
        const { container, unmount } = render(<Dateiansicht api={api} scope="allgemein" pfad="a.md" darfSchreiben={false} />);
        await warten();

        expect(textOf(container)).toContain('nur lesen');
        expect([...container.querySelectorAll('button')].some((b) => b.textContent?.includes('Bearbeiten'))).toBe(false);
        unmount();
    });

    it('laedt Binaerdateien nicht als Text, sondern bietet sie zum Herunterladen an', async () => {
        const s = falscherServer({});
        const api = vaultApi({ basisUrl: '/vault/api', csrfToken: 't', fetch: s.holen });
        const { container, unmount } = render(<Dateiansicht api={api} scope="private" pfad="tabelle.xlsx" darfSchreiben />);
        await warten();

        expect(s.mitschrift).toHaveLength(0);
        expect(container.querySelector('a')?.getAttribute('href')).toBe('/vault/api/roh?scope=private&path=tabelle.xlsx&download=1');
        unmount();
    });
});
