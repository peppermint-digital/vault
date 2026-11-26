import { act } from 'react';
import { describe, expect, it } from 'vitest';
import { Ordnerbaum } from '../src/Ordnerbaum';
import { vaultApi } from '../src/transport';
import { VaultBrowser } from '../src/VaultBrowser';
import { falscherServer } from './support/falscherServer';
import { render } from './support/render';

const warten = () => act(async () => { await new Promise((r) => setTimeout(r, 0)); });

/*
 * 0.6.1 (Keeper A38 a, abgestimmt mit aibrain-e1): Slots, damit AI Brain
 * seinen Dateibaum ganz aufs Paket umstellen kann. Ohne Belegung wie heute.
 */
function server() {
    return falscherServer({
        'GET baum': {
            json: {
                bereich: { slug: 'private', label: 'Persönlich', persoenlich: true, darfSchreiben: true },
                baum: [{ name: 'n.md', path: 'n.md', type: 'file' }],
            },
        },
        'GET datei': { json: { path: 'n.md', content: '# n', version: 'v', file_id: null } },
    });
}

describe('Slots', () => {
    it('meldet die geoeffnete Datei auch beim Start, gibt dem Editor Bereich und Recht und zeigt Datei-Aktionen', async () => {
        const s = server();
        const api = vaultApi({ basisUrl: '/vault/api', csrfToken: 't', fetch: s.holen });
        const geoeffnet: string[] = [];
        const editor: Array<{ scope: string; darfSchreiben: boolean }> = [];
        const { container, unmount } = render(
            <VaultBrowser
                api={api}
                scope="private"
                startPfad="n.md"
                onDateiGeoeffnet={(p) => geoeffnet.push(p)}
                renderEditor={(slot) => {
                    editor.push({ scope: slot.scope, darfSchreiben: slot.darfSchreiben });
                    return <div data-editor />;
                }}
                renderDateiAktionen={({ pfad }) => <button type="button" data-aktion>Großansicht {pfad}</button>}
            />,
        );
        await warten();
        await warten();

        expect(geoeffnet).toEqual(['n.md']);
        expect(container.querySelector('[data-aktion]')?.textContent).toBe('Großansicht n.md');

        const bearbeiten = [...container.querySelectorAll('button')].find((b) => b.textContent?.includes('Bearbeiten'))!;
        act(() => bearbeiten.click());
        await warten();
        expect(editor.at(-1)).toEqual({ scope: 'private', darfSchreiben: true });
        unmount();
    });

    it('zeigt ohne Slots nichts Zusaetzliches', async () => {
        const s = server();
        const api = vaultApi({ basisUrl: '/vault/api', csrfToken: 't', fetch: s.holen });
        const { container, unmount } = render(<VaultBrowser api={api} scope="private" startPfad="n.md" />);
        await warten();
        await warten();

        expect(container.querySelector('[data-aktion]')).toBeNull();
        expect(container.querySelector('.ml-auto')).toBeNull();
        unmount();
    });

    it('setzt die Baum-Marke an Dateien und Ordner', () => {
        const { container, unmount } = render(
            <Ordnerbaum
                baum={[
                    { name: 'o', path: 'o', type: 'folder', children: [] },
                    { name: 'a.md', path: 'a.md', type: 'file' },
                ]}
                aktuellerPfad=""
                speicherSchluessel="t"
                onWaehlen={() => {}}
                renderBaumMarke={({ pfad, art }) => <i data-marke>{`${art}:${pfad}`}</i>}
            />,
        );

        expect([...container.querySelectorAll('[data-marke]')].map((m) => m.textContent)).toEqual(['ordner:o', 'datei:a.md']);
        unmount();
    });
});
