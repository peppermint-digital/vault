import { afterEach, describe, expect, it } from 'vitest';
import { csrfKoepfe, xsrfAusCookie } from '../src/csrf';
import { vaultApi } from '../src/transport';
import { falscherServer } from './support/falscherServer';

/*
 * 0.7.1 (aibrain-e1: AI Brain hat keinen csrf-Meta-Tag, Paket-Schreibwege
 * bekamen 419): Rueckfall auf X-XSRF-TOKEN aus dem Cookie, Laravel-Standard.
 */
function cookieSetzen(wert: string | null) {
    document.cookie = wert === null ? 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/' : `XSRF-TOKEN=${wert}; path=/`;
}

afterEach(() => cookieSetzen(null));

describe('CSRF-Köpfe', () => {
    it('liest das Cookie und entschlüsselt die URL-Kodierung', () => {
        expect(xsrfAusCookie('a=1; XSRF-TOKEN=eyJpdiI6%3D%3D; b=2')).toBe('eyJpdiI6==');
        expect(xsrfAusCookie('XSRF-TOKEN-ALT=x; a=1')).toBe('');
        expect(xsrfAusCookie('')).toBe('');
    });

    it('schickt X-CSRF-TOKEN nur mit Wert und X-XSRF-TOKEN, sobald das Cookie da ist', () => {
        expect(csrfKoepfe('meta')).toEqual({ 'X-CSRF-TOKEN': 'meta' });
        expect(csrfKoepfe('')).toEqual({});
        expect(csrfKoepfe(undefined)).toEqual({});

        cookieSetzen('keks%3D');
        expect(csrfKoepfe(() => '')).toEqual({ 'X-XSRF-TOKEN': 'keks=' });
        expect(csrfKoepfe('meta')).toEqual({ 'X-CSRF-TOKEN': 'meta', 'X-XSRF-TOKEN': 'keks=' });
    });

    it('lässt den Transport ohne Meta-Tag mit dem Cookie schreiben', async () => {
        cookieSetzen('keks');
        const s = falscherServer({ 'POST datei': { json: { path: 'a.md' } } });
        const api = vaultApi({ basisUrl: '/vault/api', fetch: s.holen });

        await api.anlegen('private', 'a.md', 'file');

        const kopf = s.mitschrift[0].kopf;
        expect(kopf['X-XSRF-TOKEN']).toBe('keks');
        expect(kopf).not.toHaveProperty('X-CSRF-TOKEN');
    });
});
