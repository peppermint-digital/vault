import { useCallback, useEffect, useState } from 'react';

/**
 * Breite der Dateiliste und ob sie eingeklappt ist - je Person lokal im Browser
 * gemerkt (0.7.4; Format seit 0.7.5).
 *
 * Die Breite steht als reine Zahl unter `breiteSchluessel` (Produkt-Prop
 * `baumBreiteSchluessel`, AI Brain: 'ai-brain-vault-sidebar-w' - so behalten
 * Leute ihre alte Breite), sonst unter einem eigenen Schluessel. Einklappen
 * gilt fuer breite Bildschirme; das Handy hat sein eigenes, nicht gemerktes
 * Verhalten im VaultBrowser.
 */
export const LISTE_MIN = 200;
export const LISTE_MAX = 600;
export const LISTE_VORGABE = 288; // = bisheriges md:w-72
const BREITE_VORGABE_SCHLUESSEL = 'vault-browser:liste-breite';
const EINGEKLAPPT_SCHLUESSEL = 'vault-browser:liste-eingeklappt';

export function begrenzt(breite: number): number {
    return Math.round(Math.min(LISTE_MAX, Math.max(LISTE_MIN, breite)));
}

function lesen(schluessel: string): string | null {
    try {
        return globalThis.localStorage?.getItem(schluessel) ?? null;
    } catch {
        return null;
    }
}

function schreiben(schluessel: string, wert: string): void {
    try {
        globalThis.localStorage?.setItem(schluessel, wert);
    } catch {
        // Gesperrter Speicher (privates Fenster): dann gilt es nur fuer diese Sitzung.
    }
}

export function useListenbreite(breiteSchluessel?: string) {
    const schluessel = breiteSchluessel || BREITE_VORGABE_SCHLUESSEL;
    const [breite, setBreiteRoh] = useState<number>(() => {
        const zahl = Number.parseFloat(lesen(schluessel) ?? '');
        return Number.isFinite(zahl) ? begrenzt(zahl) : LISTE_VORGABE;
    });
    const [eingeklappt, setEingeklapptRoh] = useState<boolean>(() => lesen(EINGEKLAPPT_SCHLUESSEL) === '1');

    useEffect(() => schreiben(schluessel, String(breite)), [schluessel, breite]);
    useEffect(() => schreiben(EINGEKLAPPT_SCHLUESSEL, eingeklappt ? '1' : '0'), [eingeklappt]);

    const setBreite = useCallback((wert: number) => setBreiteRoh(begrenzt(wert)), []);
    const setEingeklappt = useCallback((wert: boolean) => setEingeklapptRoh(wert), []);

    return { breite, eingeklappt, setBreite, setEingeklappt };
}
