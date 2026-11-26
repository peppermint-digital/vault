import { describe, expect, it } from 'vitest';
import { VaultFehler, VaultKonflikt, vaultApi } from '../src/transport';
import { falscherServer } from './support/falscherServer';

describe('vaultApi', () => {
    it('baut Adressen mit Bereich und kodiertem Pfad und schickt den CSRF-Token', async () => {
        const s = falscherServer({ 'GET datei': { json: { path: 'a b/ü.md', content: 'x', version: 'v', file_id: null } } });
        const api = vaultApi({ basisUrl: '/vault/api/', csrfToken: () => 'tok-1', fetch: s.holen });

        await api.datei('team', 'a b/ü.md');

        expect(s.mitschrift[0].url).toBe('/vault/api/datei?scope=team&path=a%20b%2F%C3%BC.md');
        expect(s.mitschrift[0].kopf['X-CSRF-TOKEN']).toBe('tok-1');
    });

    it('wirft bei 409 einen Konflikt mit dem fremden Stand', async () => {
        const s = falscherServer({ 'PATCH datei': { status: 409, json: { conflict: true, current_content: 'fremd', current_version: 'v2' } } });
        const api = vaultApi({ basisUrl: '/vault/api', csrfToken: 't', fetch: s.holen });

        const fehler = await api.speichern('private', 'a.md', 'meins', 'v1').catch((e) => e);

        expect(fehler).toBeInstanceOf(VaultKonflikt);
        expect(fehler.aktuellerInhalt).toBe('fremd');
        expect(s.mitschrift[0].rumpf).toEqual({ path: 'a.md', content: 'meins', base_version: 'v1' });
    });

    it('reicht die Meldung des Servers durch statt nur den Statuscode', async () => {
        const s = falscherServer({ 'POST datei': { status: 422, json: { error: 'Unter diesem Namen gibt es hier schon etwas.' } } });
        const api = vaultApi({ basisUrl: '/vault/api', csrfToken: 't', fetch: s.holen });

        const fehler = await api.anlegen('private', 'a.md', 'file').catch((e) => e);

        expect(fehler).toBeInstanceOf(VaultFehler);
        expect(fehler.status).toBe(422);
        expect(fehler.message).toBe('Unter diesem Namen gibt es hier schon etwas.');
    });

    it('meldet eine abgerissene Verbindung als Status 0', async () => {
        const api = vaultApi({ basisUrl: '/vault/api', csrfToken: 't', fetch: (async () => { throw new TypeError('offline'); }) as typeof fetch });

        const fehler = await api.baum('private').catch((e) => e);

        expect(fehler).toBeInstanceOf(VaultFehler);
        expect(fehler.status).toBe(0);
    });

    it('gibt fuer Rohdateien eine Adresse, mit Herunterladen auf Wunsch', () => {
        const api = vaultApi({ basisUrl: '/vault/api', csrfToken: 't' });

        expect(api.rohUrl('private', 'bild.png')).toBe('/vault/api/roh?scope=private&path=bild.png');
        expect(api.rohUrl('private', 'bild.png', true)).toBe('/vault/api/roh?scope=private&path=bild.png&download=1');
    });
});
