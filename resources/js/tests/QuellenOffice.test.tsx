import { act } from 'react';
import { describe, expect, it } from 'vitest';
import { vaultApi } from '../src/transport';
import { VaultBrowser } from '../src/VaultBrowser';
import { falscherServer } from './support/falscherServer';
import { render } from './support/render';

const warten = () => act(async () => { await new Promise((r) => setTimeout(r, 0)); });

/*
 * 0.7.1 (Keeper-FREI, aibrain-e1): EuroOffice in Quellen. Vorhandene
 * Office-Dateien oeffnen im Editor; ueber ihnen steht, dass Aenderungen ohne
 * Versionen direkt in die Quelle gehen und bei weggebrochener Quelle NICHT
 * gespeichert werden. Neue Office-Dateien legt der Dialog in Quellen nicht an.
 */
function server(darfSchreiben: boolean) {
    return falscherServer({
        'GET quelle/ebene': {
            json: {
                erreichbar: true,
                bereich: { slug: 'extern:grafiken', label: 'Grafiken', persoenlich: false, darfSchreiben },
                eintraege: [{ name: 'brief.docx', path: 'brief.docx', type: 'file' }],
            },
        },
        'GET eurooffice/konfiguration': { status: 503, json: { error: 'nicht im Test' } },
    });
}

describe('Office in Quellen', () => {
    it('öffnet eine Office-Datei der Quelle im Editor und warnt vor dem Speichern ohne Versionen', async () => {
        const s = server(true);
        const api = vaultApi({ basisUrl: '/vault/api', fetch: s.holen });
        const { container, unmount } = render(<VaultBrowser api={api} scope="extern:grafiken" startPfad="brief.docx" euroOffice />);
        await warten();
        await warten();

        expect(container.querySelector('[data-quellen-hinweis]')?.textContent).toContain('nicht gespeichert');
        expect(s.mitschrift.some((m) => m.url.includes('eurooffice/konfiguration') && m.url.includes('scope=extern%3Agrafiken'))).toBe(true);
        unmount();
    });

    it('warnt nicht, wenn man nur lesen darf', async () => {
        const s = server(false);
        const api = vaultApi({ basisUrl: '/vault/api', fetch: s.holen });
        const { container, unmount } = render(<VaultBrowser api={api} scope="extern:grafiken" startPfad="brief.docx" euroOffice />);
        await warten();
        await warten();

        expect(container.querySelector('[data-quellen-hinweis]')).toBeNull();
        unmount();
    });

    it('bietet in Quellen keine neuen Office-Dateien an', async () => {
        const s = server(true);
        const api = vaultApi({ basisUrl: '/vault/api', fetch: s.holen });
        const { container, unmount } = render(<VaultBrowser api={api} scope="extern:grafiken" euroOffice />);
        await warten();

        act(() => [...container.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.getAttribute('aria-label') === 'Neue Datei')!.click());
        await warten();

        expect(document.body.textContent).not.toContain('Tabelle');
        unmount();
    });
});
