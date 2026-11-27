import { act } from 'react';
import { describe, expect, it } from 'vitest';
import type { AnsichtSlot } from '../src/Dateiansicht';
import { vaultApi } from '../src/transport';
import { VaultBrowser } from '../src/VaultBrowser';
import { falscherServer, type Mitschrift } from './support/falscherServer';
import { render } from './support/render';

const warten = () => act(async () => { await new Promise((r) => setTimeout(r, 0)); });

/*
 * 0.7.3 (aibrain-e1, Keeper A60): renderAnsicht bekommt scope, oeffnen und
 * speichern - Wikilinks wechseln die Datei, ein angehaktes Kaestchen speichert
 * ueber denselben Weg wie der Editor (Version, Konfliktkasten).
 */
function server(darfSchreiben: boolean, speichernAntwort: (m: Mitschrift) => { status?: number; json: unknown }) {
    return falscherServer({
        'GET baum': {
            json: {
                bereich: { slug: 'private', label: 'Persönlich', persoenlich: true, darfSchreiben },
                baum: [
                    { name: 'liste.md', path: 'liste.md', type: 'file' },
                    { name: 'ziel.md', path: 'ziel.md', type: 'file' },
                ],
            },
        },
        'GET datei': (m: Mitschrift) => {
            const p = new URL(m.url, 'http://x').searchParams.get('path');
            return { json: { path: p, content: p === 'liste.md' ? '- [ ] Brot' : '# Ziel', version: 'v1', file_id: null } };
        },
        'PATCH datei': speichernAntwort,
        'GET verweise': { json: { backlinks: [] } },
    });
}

function mitSlot(darfSchreiben: boolean, antwort: Parameters<typeof server>[1]) {
    const s = server(darfSchreiben, antwort);
    const api = vaultApi({ basisUrl: '/vault/api', fetch: s.holen });
    const slots: AnsichtSlot[] = [];
    const pfade: string[] = [];
    const r = render(
        <VaultBrowser
            api={api}
            scope="private"
            startPfad="liste.md"
            onPfadGewechselt={(p) => pfade.push(p)}
            renderAnsicht={(slot) => {
                slots.push(slot);
                return <div data-ansicht>{slot.inhalt}</div>;
            }}
        />,
    );
    return { s, slots, pfade, ...r };
}

describe('renderAnsicht-Slot', () => {
    it('bekommt den Bereich und öffnet über oeffnen eine andere Datei wie ein Klick im Baum', async () => {
        const { slots, pfade, container, unmount } = mitSlot(true, () => ({ json: { saved: true, version: 'v2' } }));
        await warten();
        await warten();

        expect(slots.at(-1)?.scope).toBe('private');
        act(() => slots.at(-1)!.oeffnen('ziel.md'));
        await warten();
        await warten();

        expect(pfade.at(-1)).toBe('ziel.md');
        expect(container.querySelector('[data-ansicht]')?.textContent).toBe('# Ziel');
        unmount();
    });

    it('speichert über denselben Weg wie der Editor - mit Basis-Version, danach neuer Inhalt', async () => {
        const { s, slots, container, unmount } = mitSlot(true, () => ({ json: { saved: true, version: 'v2' } }));
        await warten();
        await warten();

        await act(async () => {
            await slots.at(-1)!.speichern!('- [x] Brot');
        });

        const anfrage = s.mitschrift.find((m) => m.methode === 'PATCH')!;
        expect(anfrage.rumpf).toMatchObject({ path: 'liste.md', content: '- [x] Brot', base_version: 'v1' });
        expect(container.querySelector('[data-ansicht]')?.textContent).toBe('- [x] Brot');
        unmount();
    });

    it('zeigt bei einem Konflikt den Kasten, macht den eigenen Stand zum Entwurf und lehnt ab', async () => {
        const { slots, container, unmount } = mitSlot(true, (m) =>
            (m.rumpf as { force?: boolean }).force
                ? { json: { saved: true, version: 'v3' } }
                : { status: 409, json: { conflict: true, current_content: '- [ ] Brot\n- [ ] Milch', current_version: 'v2' } },
        );
        await warten();
        await warten();

        let abgelehnt = false;
        await act(async () => {
            await slots.at(-1)!.speichern!('- [x] Brot').catch(() => (abgelehnt = true));
        });

        expect(abgelehnt).toBe(true);
        expect(container.textContent).toContain('Jemand hat diese Datei geändert');
        expect((container.querySelector('textarea') as HTMLTextAreaElement | null)?.value).toBe('- [x] Brot');
        unmount();
    });

    it('gibt ohne Schreibrecht kein speichern mit', async () => {
        const { slots, unmount } = mitSlot(false, () => ({ json: { saved: true, version: 'v2' } }));
        await warten();
        await warten();

        expect(slots.at(-1)?.speichern).toBeUndefined();
        unmount();
    });
});
