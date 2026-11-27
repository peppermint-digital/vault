import { act } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { begrenzt, LISTE_MAX, LISTE_MIN } from '../src/listenbreite';
import { vaultApi } from '../src/transport';
import { VaultBrowser } from '../src/VaultBrowser';
import { falscherServer } from './support/falscherServer';
import { render } from './support/render';

const warten = () => act(async () => { await new Promise((r) => setTimeout(r, 0)); });

afterEach(() => {
    vi.restoreAllMocks();
    globalThis.localStorage?.clear();
});

/*
 * 0.7.4 (Keeper): Dateiliste breiter/schmaler ziehen und einklappen, je Person
 * lokal gemerkt; auf dem Handy unveraendert (alles nur ab md).
 */
function browser() {
    const s = falscherServer({
        'GET baum': { json: { bereich: { slug: 'private', label: 'Persönlich', persoenlich: true, darfSchreiben: true }, baum: [] } },
    });
    const api = vaultApi({ basisUrl: '/vault/api', fetch: s.holen });
    return render(<VaultBrowser api={api} scope="private" />);
}

const trenner = (c: HTMLElement) => c.querySelector<HTMLElement>('[role="separator"]');
const breiteVar = (c: HTMLElement) => (c.firstElementChild as HTMLElement).style.getPropertyValue('--vault-liste-breite');

describe('Dateiliste', () => {
    it('startet mit der bisherigen Breite und lässt sich per Pfeiltaste ändern und merkt sich das', async () => {
        const a = browser();
        await warten();
        expect(trenner(a.container)?.getAttribute('aria-valuenow')).toBe('288');
        expect(breiteVar(a.container)).toBe('288px');

        act(() => trenner(a.container)!.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true })));
        expect(breiteVar(a.container)).toBe('304px');
        a.unmount();

        const b = browser();
        await warten();
        expect(trenner(b.container)?.getAttribute('aria-valuenow')).toBe('304');
        b.unmount();
    });

    it('folgt dem Ziehen mit der Maus', async () => {
        const { container, unmount } = browser();
        await warten();

        act(() => trenner(container)!.dispatchEvent(new MouseEvent('pointerdown', { clientX: 100, bubbles: true }) as PointerEvent));
        act(() => window.dispatchEvent(new MouseEvent('pointermove', { clientX: 180 }) as PointerEvent));
        act(() => window.dispatchEvent(new MouseEvent('pointerup', {}) as PointerEvent));
        act(() => window.dispatchEvent(new MouseEvent('pointermove', { clientX: 500 }) as PointerEvent));

        expect(breiteVar(container)).toBe('368px');
        unmount();
    });

    it('hält die Breite in Grenzen', () => {
        expect(begrenzt(10)).toBe(LISTE_MIN);
        expect(begrenzt(5000)).toBe(LISTE_MAX);
        expect(begrenzt(300.4)).toBe(300);
    });

    it('klappt ein und auf, nur ab md, und merkt es sich', async () => {
        const a = browser();
        await warten();
        const knopf = (name: string) => [...a.container.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.getAttribute('aria-label') === name);

        expect(knopf('Dateiliste einklappen')?.className).toContain('md:inline-flex');
        act(() => knopf('Dateiliste einklappen')!.click());
        const liste = a.container.querySelector('[data-dateiliste]')!;
        expect(liste.className).toContain('md:hidden');
        expect(liste.className).not.toMatch(/(^| )hidden( |$)/);
        expect(trenner(a.container)).toBeNull();
        a.unmount();

        const b = browser();
        await warten();
        const auf = [...b.container.querySelectorAll<HTMLButtonElement>('button')].find((x) => x.getAttribute('aria-label') === 'Dateiliste aufklappen');
        expect(auf).toBeDefined();
        act(() => auf!.click());
        expect(b.container.querySelector('[data-dateiliste]')!.className).not.toContain('md:hidden');
        b.unmount();
    });

    it('funktioniert auch mit gesperrtem Speicher', async () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('gesperrt');
        });
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('gesperrt');
        });
        const { container, unmount } = browser();
        await warten();

        act(() => trenner(container)!.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true })));
        expect(breiteVar(container)).toBe('272px');
        unmount();
    });
});
