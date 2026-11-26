import { act } from 'react';
import { describe, expect, it } from 'vitest';
import { gezogenePosten, ordnerPosten } from '../src/hochladen';
import { vaultApi } from '../src/transport';
import { VaultBrowser } from '../src/VaultBrowser';
import { falscherServer, type Mitschrift } from './support/falscherServer';
import { render } from './support/render';

const warten = () =>
    act(async () => {
        await new Promise((r) => setTimeout(r, 0));
    });

function datei(name: string, relativ = ''): File {
    const d = new File(['x'], name);
    if (relativ) {
        Object.defineProperty(d, 'webkitRelativePath', { value: relativ });
    }
    return d;
}

function uebergabe(dateien: File[], ordnerAn: number[] = []): DataTransfer {
    return {
        types: ['Files'],
        files: dateien as unknown as FileList,
        items: dateien.map((_, i) => ({ webkitGetAsEntry: () => ({ isFile: !ordnerAn.includes(i) }) })) as unknown as DataTransferItemList,
    } as unknown as DataTransfer;
}

/** Ziel-Ordner je Hochlade-Anfrage, in Reihenfolge. */
function ziele(mitschrift: Mitschrift[]): Array<[string, string]> {
    return mitschrift
        .filter((m) => m.methode === 'POST' && m.url.includes('hochladen'))
        .map((m) => {
            const f = m.rumpf as FormData;
            return [(f.get('file') as File).name, String(f.get('folder') ?? '')];
        });
}

/*
 * 0.6.2 (aus AI Brain, Keeper: ins Paket statt Slot): Dateien auf den Baum
 * ziehen und ganze Ordner hochladen — Unterordner bleiben erhalten.
 */
describe('Ziele beim Hochladen', () => {
    it('behaelt die Struktur eines Ordners unter dem Ziel', () => {
        const posten = ordnerPosten('Projekte', [datei('a.txt', 'Neu/a.txt'), datei('b.txt', 'Neu/unter/b.txt')]);
        expect(posten.map((p) => p.ordner)).toEqual(['Projekte/Neu', 'Projekte/Neu/unter']);
        expect(ordnerPosten('', [datei('a.txt', 'Neu/a.txt')])[0].ordner).toBe('Neu');
    });

    it('laesst keine Pfad-Tricks durch', () => {
        expect(ordnerPosten('x', [datei('a.txt', '../../a.txt')])[0].ordner).toBe('x');
    });

    it('ueberspringt gezogene Ordner und nimmt nur Dateien', () => {
        const posten = gezogenePosten('o', uebergabe([datei('a.txt'), datei('Ordner'), datei('b.txt')], [1]));
        expect(posten.map((p) => [p.datei.name, p.ordner])).toEqual([
            ['a.txt', 'o'],
            ['b.txt', 'o'],
        ]);
    });
});

function server() {
    let n = 0;
    return falscherServer({
        'GET baum': {
            json: {
                bereich: { slug: 'private', label: 'Persönlich', persoenlich: true, darfSchreiben: true },
                baum: [{ name: 'Projekte', path: 'Projekte', type: 'folder', children: [{ name: 'n.md', path: 'Projekte/n.md', type: 'file' }] }],
            },
        },
        'GET datei': { json: { path: 'Projekte/n.md', content: '# n', version: 'v', file_id: null } },
        'POST hochladen': () => ({ json: { path: `hoch-${++n}`, file_id: null } }),
    });
}

describe('Ziehen und Ordner im Browser', () => {
    it('laedt Gezogenes nacheinander in den Ordner der offenen Datei', async () => {
        const s = server();
        const api = vaultApi({ basisUrl: '/vault/api', csrfToken: 't', fetch: s.holen });
        const { container, unmount } = render(<VaultBrowser api={api} scope="private" startPfad="Projekte/n.md" />);
        await warten();

        const ablage = container.querySelector('[data-ablage]')!;
        const ereignis = new Event('drop', { bubbles: true, cancelable: true });
        Object.defineProperty(ereignis, 'dataTransfer', { value: uebergabe([datei('a.pdf'), datei('b.pdf')]) });
        act(() => ablage.dispatchEvent(ereignis));
        await warten();
        await warten();

        expect(ereignis.defaultPrevented).toBe(true);
        expect(ziele(s.mitschrift)).toEqual([
            ['a.pdf', 'Projekte'],
            ['b.pdf', 'Projekte'],
        ]);
        unmount();
    });

    it('nimmt einen ausgewaehlten Ordner samt Unterordnern', async () => {
        const s = server();
        const api = vaultApi({ basisUrl: '/vault/api', csrfToken: 't', fetch: s.holen });
        const { container, unmount } = render(<VaultBrowser api={api} scope="private" />);
        await warten();

        const feld = container.querySelector<HTMLInputElement>('input[webkitdirectory]')!;
        Object.defineProperty(feld, 'files', { value: [datei('a.txt', 'Neu/a.txt'), datei('b.txt', 'Neu/unter/b.txt')], configurable: true });
        act(() => feld.dispatchEvent(new Event('change', { bubbles: true })));
        await warten();
        await warten();

        expect(ziele(s.mitschrift)).toEqual([
            ['a.txt', 'Neu'],
            ['b.txt', 'Neu/unter'],
        ]);
        unmount();
    });

    it('nimmt nichts an, wenn man nicht schreiben darf', async () => {
        const s = falscherServer({
            'GET baum': { json: { bereich: { slug: 'g', label: 'G', persoenlich: false, darfSchreiben: false }, baum: [] } },
        });
        const api = vaultApi({ basisUrl: '/vault/api', csrfToken: 't', fetch: s.holen });
        const { container, unmount } = render(<VaultBrowser api={api} scope="g" />);
        await warten();

        expect(container.querySelector('[data-ablage]')).toBeNull();
        expect(container.querySelector('[aria-label="Ordner hochladen"]')).toBeNull();
        unmount();
    });
});
