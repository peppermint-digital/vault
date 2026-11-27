import { useCallback, useEffect, useState } from 'react';

/**
 * Breite der Dateiliste und ob sie eingeklappt ist (0.7.4) - je Person lokal im
 * Browser gemerkt. Nur fuer breite Bildschirme; auf dem Handy bleibt die Liste,
 * wie sie war (volle Breite ueber der Datei).
 */
export const LISTE_MIN = 200;
export const LISTE_MAX = 600;
export const LISTE_VORGABE = 288; // = bisheriges md:w-72
const SCHLUESSEL = 'vault-browser:liste';

interface Stand {
    breite: number;
    eingeklappt: boolean;
}

export function begrenzt(breite: number): number {
    return Math.round(Math.min(LISTE_MAX, Math.max(LISTE_MIN, breite)));
}

function lesen(): Stand {
    try {
        const roh = globalThis.localStorage?.getItem(SCHLUESSEL);
        const d = roh ? (JSON.parse(roh) as Partial<Stand>) : {};
        return {
            breite: typeof d.breite === 'number' ? begrenzt(d.breite) : LISTE_VORGABE,
            eingeklappt: d.eingeklappt === true,
        };
    } catch {
        return { breite: LISTE_VORGABE, eingeklappt: false };
    }
}

export function useListenbreite() {
    const [stand, setStand] = useState<Stand>(lesen);

    useEffect(() => {
        try {
            globalThis.localStorage?.setItem(SCHLUESSEL, JSON.stringify(stand));
        } catch {
            // Gesperrter Speicher (privates Fenster): dann gilt es nur fuer diese Sitzung.
        }
    }, [stand]);

    const setBreite = useCallback((breite: number) => setStand((s) => ({ ...s, breite: begrenzt(breite) })), []);
    const setEingeklappt = useCallback((eingeklappt: boolean) => setStand((s) => ({ ...s, eingeklappt })), []);

    return { breite: stand.breite, eingeklappt: stand.eingeklappt, setBreite, setEingeklappt };
}
