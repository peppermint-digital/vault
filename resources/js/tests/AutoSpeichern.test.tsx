import { act } from 'react';
import { describe, expect, it } from 'vitest';
import type { EditorSlot } from '../src/Dateiansicht';
import { vaultApi } from '../src/transport';
import { VaultBrowser } from '../src/VaultBrowser';
import { falscherServer, type Mitschrift } from './support/falscherServer';
import { render } from './support/render';

const warten = (ms = 0) => act(async () => { await new Promise((r) => setTimeout(r, ms)); });

/*
 * 0.7.4 (Keeper, mit aibrain-e1 abgestimmt): Zeichnungen speichern sich beim
 * Bearbeiten selbst - ueber denselben Weg wie „Speichern", Vorgabe aus.
 */
function aufbau(opts: { auto?: { dateien?: RegExp | ((p: string) => boolean); nachMs?: number }; datei?: string; patch?: (m: Mitschrift, n: number) => { status?: number; json: unknown } }) {
    const datei = opts.datei ?? 'skizze.excalidraw';
    let n = 0;
    const s = falscherServer({
        'GET baum': { json: { bereich: { slug: 'private', label: 'P', persoenlich: true, darfSchreiben: true }, baum: [{ name: datei, path: datei, type: 'file' }] } },
        'GET datei': { json: { path: datei, content: '{}', version: 'v1', file_id: null } },
        'PATCH datei': (m) => (opts.patch ? opts.patch(m, ++n) : { json: { saved: true, version: `v${++n + 1}` } }),
        'GET verweise': { json: { backlinks: [] } },
    });
    const api = vaultApi({ basisUrl: '/vault/api', fetch: s.holen });
    const slots: EditorSlot[] = [];
    const r = render(
        <VaultBrowser
            api={api}
            scope="private"
            startPfad={datei}
            autoSpeichern={opts.auto}
            renderEditor={(slot) => {
                slots.push(slot);
                return <div data-editor>{slot.wert}</div>;
            }}
        />,
    );
    const bearbeiten = () => act(() => [...r.container.querySelectorAll('button')].find((b) => b.textContent?.includes('Bearbeiten'))!.click());
    const patches = () => s.mitschrift.filter((m) => m.methode === 'PATCH').map((m) => m.rumpf as { content: string; base_version: string });
    return { ...r, slots, bearbeiten, patches };
}

describe('Automatisch speichern', () => {
    it('speichert nach der Ruhezeit mit der richtigen Version und lässt den Editor offen', async () => {
        const t = aufbau({ auto: { nachMs: 50 } });
        await warten();
        await warten();
        t.bearbeiten();
        act(() => t.slots.at(-1)!.onAendern('{"a":1}'));
        await warten(20);
        expect(t.patches()).toHaveLength(0);
        await warten(80);

        expect(t.patches()).toEqual([expect.objectContaining({ content: '{"a":1}', base_version: 'v1' })]);
        expect(t.container.querySelector('[data-editor]')).not.toBeNull();
        expect(t.container.textContent).toContain('Automatisch gespeichert');

        act(() => t.slots.at(-1)!.onAendern('{"a":2}'));
        await warten(100);
        expect(t.patches().at(-1)).toEqual(expect.objectContaining({ content: '{"a":2}', base_version: 'v2' }));
        t.unmount();
    });

    it('hält bei einem Konflikt an und zeigt den Kasten', async () => {
        const t = aufbau({ auto: { nachMs: 30 }, patch: () => ({ status: 409, json: { conflict: true, current_content: '{"fremd":1}', current_version: 'v9' } }) });
        await warten();
        await warten();
        t.bearbeiten();
        act(() => t.slots.at(-1)!.onAendern('{"a":1}'));
        await warten(80);
        expect(t.container.textContent).toContain('Jemand hat diese Datei geändert');

        act(() => t.slots.at(-1)!.onAendern('{"a":2}'));
        await warten(80);
        expect(t.patches()).toHaveLength(1);
        t.unmount();
    });

    it('ist ohne Einschalten und bei anderen Dateien aus', async () => {
        const aus = aufbau({});
        await warten();
        await warten();
        aus.bearbeiten();
        act(() => aus.slots.at(-1)!.onAendern('{"a":1}'));
        await warten(2100);
        expect(aus.patches()).toHaveLength(0);
        aus.unmount();

        const md = aufbau({ auto: { nachMs: 30 }, datei: 'notiz.md' });
        await warten();
        await warten();
        md.bearbeiten();
        act(() => md.slots.at(-1)!.onAendern('# neu'));
        await warten(80);
        expect(md.patches()).toHaveLength(0);
        md.unmount();

        const fn = aufbau({ auto: { nachMs: 30, dateien: (p) => p.endsWith('.md') }, datei: 'notiz.md' });
        await warten();
        await warten();
        fn.bearbeiten();
        act(() => fn.slots.at(-1)!.onAendern('# neu'));
        await warten(80);
        expect(fn.patches()).toHaveLength(1);
        fn.unmount();
    }, 5000);

    it('speichert beim Verlassen sofort, was noch offen ist', async () => {
        const t = aufbau({ auto: { nachMs: 5000 } });
        await warten();
        await warten();
        t.bearbeiten();
        act(() => t.slots.at(-1)!.onAendern('{"zuletzt":1}'));
        t.unmount();
        await warten(10);

        expect(t.patches()).toEqual([expect.objectContaining({ content: '{"zuletzt":1}', base_version: 'v1' })]);
    });
});
