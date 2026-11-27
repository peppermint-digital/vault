import type { Baumeintrag } from './typen';

/**
 * Wohin ein [[Wikilink]] zeigt - der wortgleiche Port von `Verweise::aufloesen`
 * (PHP, src/Verweise.php), damit Klick, Graph und „Verweise hierher" dieselbe
 * Datei meinen (0.7.6, #10335). Gemeinsame Faelle: tests/fixtures/verweis-ziele.json,
 * gegen die der PHP- UND der JS-Test laufen.
 *
 * Bewusst mit den Eigenheiten der PHP-Regel:
 * - Gross/klein nur fuer A-Z (PHP `strtolower` ist byteweise): `[[ümlaut]]` findet
 *   `Ümlaut.md` nicht. Gemeldet, nicht hier „verbessert" - sonst liefe der Klick
 *   vom Graphen weg.
 * - Bei gleichem Namen gewinnt die nach Pfad (Zeichenvergleich) spaetere Datei.
 */
const INDIZIERT = ['md', 'html'];
const INDIZIERT_MUSTER = /\.(md|html)$/i;

function klein(text: string): string {
    return text.replace(/[A-Z]/g, (z) => z.toLowerCase());
}

function basisname(pfad: string): string {
    return pfad.replace(/\/+$/, '').split('/').pop() ?? '';
}

function ohneEndung(name: string): { ohne: string; endung: string } {
    const i = name.lastIndexOf('.');
    // Wie PHP pathinfo: auch ein fuehrender Punkt trennt (`.notiz` -> ohne '', endung 'notiz').
    return i === -1 ? { ohne: name, endung: '' } : { ohne: name.slice(0, i), endung: name.slice(i + 1) };
}

function eltern(pfad: string): string {
    const i = pfad.lastIndexOf('/');
    return i === -1 ? '.' : pfad.slice(0, i);
}

function register(pfade: string[]): Map<string, string> {
    const r = new Map<string, string>();
    for (const rel of pfade) {
        const { ohne, endung } = ohneEndung(basisname(rel));
        r.set(klein(rel), rel);
        r.set(klein(rel.replace(INDIZIERT_MUSTER, '')), rel);
        r.set(klein(ohne), rel);
        r.set(klein(`${ohne}.${klein(endung)}`), rel);
    }
    return r;
}

function normalisieren(pfad: string): string | null {
    const teile: string[] = [];
    for (const segment of pfad.split('/')) {
        if (segment === '..') {
            if (teile.length === 0) {
                return null;
            }
            teile.pop();
        } else if (segment !== '' && segment !== '.') {
            teile.push(segment);
        }
    }
    return teile.join('/');
}

function aufloesen(link: string, praefix: string, r: Map<string, string>): string | null {
    const mitEndung = (kandidat: string): string | null => {
        if (r.has(kandidat)) {
            return r.get(kandidat)!;
        }
        for (const endung of INDIZIERT) {
            const k = kandidat.endsWith(`.${endung}`) ? kandidat : `${kandidat}.${endung}`;
            if (r.has(k)) {
                return r.get(k)!;
            }
        }
        return null;
    };

    const direkt = mitEndung(klein(link));
    if (direkt !== null) {
        return direkt;
    }

    if (praefix !== '' && link.includes('/')) {
        const normal = normalisieren(praefix + link);
        const ziel = normal !== null ? mitEndung(klein(normal)) : null;
        if (ziel !== null) {
            return ziel;
        }
    } else if (praefix !== '' && !link.startsWith('..')) {
        for (const endung of INDIZIERT) {
            const k = `${klein(praefix + link)}.${endung}`;
            if (r.has(k)) {
                return r.get(k)!;
            }
        }
    }

    return mitEndung(klein(basisname(link)));
}

/** `[[Name|Text]]`, `[[Name#Abschnitt]]` oder schon `Name` -> `Name`. */
export function wikilinkName(roh: string): string {
    const innen = roh.trim().replace(/^\[\[/, '').replace(/\]\]$/, '');
    return innen.split(/[|#]/)[0].trim();
}

/** Ziel aus einer Liste aller Dateien des Bereichs (wie `Verweise::zielFuer`). */
export function verweisZielIn(pfade: string[], von: string, link: string): string | null {
    const indiziert = pfade.filter((p) => INDIZIERT_MUSTER.test(p)).sort((a, b) => (a < b ? -1 : a > b ? 1 : 0));
    const e = eltern(von);
    const praefix = e === '.' || e === '' ? '' : `${e}/`;
    return aufloesen(wikilinkName(link), praefix, register(indiziert));
}

function dateienIn(baum: Baumeintrag[]): string[] {
    return baum.flatMap((e) => (e.type === 'folder' ? dateienIn(e.children ?? []) : [e.path]));
}

/** Ziel eines [[Links]] aus der Datei `von`, im geladenen Baum des Bereichs. */
export function verweisZiel(baum: Baumeintrag[], von: string, link: string): string | null {
    return verweisZielIn(dateienIn(baum), von, link);
}
