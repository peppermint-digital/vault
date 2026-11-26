import { act } from 'react';
import { describe, expect, it } from 'vitest';
import { GeteilteAnsicht } from '../src/GeteilteAnsicht';
import { render, textOf } from './support/render';

const warten = () => act(async () => { await new Promise((r) => setTimeout(r, 0)); });
const json = (status: number, daten: unknown) => new Response(JSON.stringify(daten), { status, headers: { 'Content-Type': 'application/json' } });
const info = { name: 'seite.html', format: 'html', sharedBy: 'Anna', expiresAt: null, rohUrl: '/vault/geteilt/t1/roh', gesperrt: false, content: null };

describe('GeteilteAnsicht', () => {
    it('zeigt HTML in einem iframe ohne Zugriff auf die eigene Herkunft', async () => {
        const { container, unmount } = render(<GeteilteAnsicht token="t1" csrfToken="c" fetch={(async () => json(200, info)) as typeof fetch} />);
        await warten();

        const rahmen = container.querySelector('iframe')!;
        expect(rahmen.getAttribute('src')).toBe('/vault/geteilt/t1/roh');
        expect(rahmen.getAttribute('sandbox')).not.toContain('allow-same-origin');
        unmount();
    });

    it('fragt nach dem Passwort und laedt nach dem Entsperren neu', async () => {
        let offen = false;
        const holen = (async (url: string, init?: RequestInit) => {
            if (String(url).endsWith('/entsperren')) {
                offen = JSON.parse(String(init?.body)).password === 'richtig';
                return offen ? json(200, { ok: true }) : json(422, {});
            }
            return json(200, { ...info, format: 'md', gesperrt: !offen, content: offen ? 'Inhalt' : null });
        }) as typeof fetch;
        const { container, unmount } = render(<GeteilteAnsicht token="t1" csrfToken="c" fetch={holen} />);
        await warten();
        expect(textOf(container)).toContain('Passwort');

        const feld = container.querySelector('input')!;
        act(() => {
            Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(feld, 'richtig');
            feld.dispatchEvent(new Event('input', { bubbles: true }));
        });
        act(() => {
            container.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        });
        await warten();
        await warten();

        expect(textOf(container)).toContain('Inhalt');
        unmount();
    });

    it('sagt bei einem abgelaufenen Link, was los ist', async () => {
        const { container, unmount } = render(<GeteilteAnsicht token="t1" csrfToken="c" fetch={(async () => json(410, {})) as typeof fetch} />);
        await warten();

        expect(textOf(container)).toContain('abgelaufen');
        unmount();
    });
});
