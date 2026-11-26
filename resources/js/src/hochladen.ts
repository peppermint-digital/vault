/**
 * Hochladen mehrerer Dateien (0.6.2, aus AI Brain uebernommen): per Ziehen
 * auf den Baum und als ganzer Ordner. Der Server legt fehlende Unterordner
 * selbst an; hier wird nur bestimmt, wohin jede Datei gehoert.
 */
export interface Hochladeposten {
    datei: File;
    ordner: string;
}

function verbinden(...teile: string[]): string {
    return teile
        .flatMap((t) => t.split('/'))
        .filter((t) => t !== '' && t !== '.' && t !== '..')
        .join('/');
}

/**
 * Ein ausgewaehlter Ordner: `webkitRelativePath` ist „Ordner/unter/a.txt" —
 * die Datei landet in `<ziel>/Ordner/unter`, die Struktur bleibt erhalten.
 */
export function ordnerPosten(ziel: string, dateien: Iterable<File>): Hochladeposten[] {
    return [...dateien].map((datei) => {
        const relativ = (datei as File & { webkitRelativePath?: string }).webkitRelativePath ?? '';
        const i = relativ.lastIndexOf('/');
        return {
            datei,
            ordner: verbinden(ziel, i === -1 ? '' : relativ.slice(0, i)),
        };
    });
}

/**
 * Gezogene Dateien. Ordner im Zug werden uebersprungen (der Browser liefert
 * sie als leere „Datei" ohne Inhalt); fuer ganze Ordner gibt es den Knopf.
 */
export function gezogenePosten(ziel: string, uebergabe: DataTransfer): Hochladeposten[] {
    const eintraege = [...uebergabe.items];
    return [...uebergabe.files]
        .filter((_, i) => {
            const eintrag = eintraege[i]?.webkitGetAsEntry?.();
            return eintrag ? eintrag.isFile : true;
        })
        .map((datei) => ({ datei, ordner: verbinden(ziel) }));
}
