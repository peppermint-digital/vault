import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { act } from 'react';
import { describe, expect, it } from 'vitest';
import AnmerkungsListe from '../src/anmerkungen/AnmerkungsListe';
import { colorHex, normalizeSelection } from '../src/anmerkungen/farben';
import { Dateiansicht } from '../src/Dateiansicht';
import { vaultApi } from '../src/transport';
import { falscherServer } from './support/falscherServer';
import { click, render, textOf } from './support/render';

const warten = () => act(async () => { await new Promise((r) => setTimeout(r, 0)); });

describe('Anmerkungen', () => {
    it('listet Markierungen mit Zitat und Notiz und loescht auf Wunsch', () => {
        let geloescht = '';
        const { container, unmount } = render(
            <AnmerkungsListe items={[{ key: '1-0', color: 'green', quote: 'Zitat', note: 'Notiz', context: 'Seite 1', onDelete: () => (geloescht = '1-0') }]} />,
        );

        expect(textOf(container)).toContain('Zitat');
        expect(textOf(container)).toContain('Notiz');
        click([...container.querySelectorAll('button')].find((b) => b.getAttribute('title')?.includes('ntfernen') || b.getAttribute('aria-label')?.includes('ntfernen') || b.textContent === '')!);
        expect(geloescht).toBe('1-0');
        unmount();
    });

    it('faltet Leerraum einer Auswahl und kennt die Farben von PyMuPDF', () => {
        expect(normalizeSelection('  Hallo\n   Welt ')).toBe('Hallo Welt');
        expect(colorHex('green')).toBe('#80D966');
        expect(colorHex(null)).toBe('#FFEB3B');
    });

    it('setzt fuer PDFs den Slot des Produkts ein und bietet sonst nur Herunterladen an', async () => {
        const api = vaultApi({ basisUrl: '/vault/api', csrfToken: 't', fetch: falscherServer({}).holen });

        const mit = render(<Dateiansicht api={api} scope="private" pfad="akte.pdf" darfSchreiben renderPdf={({ pfad }) => <p>Annotator fuer {pfad}</p>} />);
        expect(textOf(mit.container)).toContain('Annotator fuer akte.pdf');
        mit.unmount();

        const ohne = render(<Dateiansicht api={api} scope="private" pfad="akte.pdf" darfSchreiben />);
        expect(ohne.container.querySelector('a')?.getAttribute('href')).toContain('download=1');
        ohne.unmount();
    });

    it('oeffnet ein EPUB mit Lesefassung im Annotator und laedt seine Anmerkungen', async () => {
        const s = falscherServer({ 'GET anmerkungen': { json: { type: 'epub', annotations: [] } } });
        const api = vaultApi({ basisUrl: '/vault/api', csrfToken: 't', fetch: s.holen });
        const { container, unmount } = render(
            <Dateiansicht api={api} scope="private" pfad="buecher/roman.epub" darfSchreiben epubLesefassungVorhanden={(p) => p === 'buecher/roman.html'} />,
        );
        await warten();

        expect(container.querySelector('iframe')?.getAttribute('src')).toBe('/vault/api/roh?scope=private&path=buecher%2Froman.html');
        expect(s.mitschrift.some((m) => m.url.includes('anmerkungen?scope=private&path=buecher%2Froman.epub'))).toBe(true);
        unmount();
    });
});

describe('Verpackung', () => {
    it('zieht pdf.js nicht in den Haupteinstieg — sonst muesste jedes Produkt es installieren', () => {
        const quellen = ['index.ts', 'Dateiansicht.tsx', 'VaultBrowser.tsx'].map((d) => readFileSync(resolve(__dirname, '../src', d), 'utf8'));

        for (const q of quellen) {
            expect(q).not.toMatch(/PdfAnnotator['"]|from 'pdfjs-dist'/);
        }
        expect(readFileSync(resolve(__dirname, '../src/anmerken.ts'), 'utf8')).toContain('PdfAnnotator');
    });
});
