/**
 * Die Anbindung an die JSON-Schiene von peppermint/vault (`routes/vault.php`).
 *
 * Die Adresse gehoert dem Produkt (`vault.routen.praefix`), ebenso der Ort des
 * CSRF-Tokens — das Paket fragt nach dem Wert, nicht nach der Stelle
 * (Muster mailbox `transport.ts`).
 *
 * Fehler werden geworfen, nicht verschluckt: Ein Speichern, das scheitert,
 * darf nie wie ein gelungenes aussehen. Der Konflikt (409) hat einen eigenen
 * Typ, weil er kein Fehler ist, sondern eine Frage an die Person.
 */
import type { Baumeintrag, Suchtreffer } from './typen';

export interface VaultApiOptionen {
    /** z.B. `/vault/api` — ohne Schraegstrich am Ende. */
    basisUrl: string;
    /** Den CSRF-Token dort lesen, wo das Produkt ihn haelt. */
    csrfToken: string | (() => string);
    /** Fuer Tests und Produkte mit eigenem Client. */
    fetch?: typeof globalThis.fetch;
}

export interface Bereichsangabe {
    slug: string;
    label: string;
    persoenlich: boolean;
    darfSchreiben: boolean;
}

export interface Dateiinhalt {
    path: string;
    content: string;
    version: string;
    file_id: string | null;
}

export interface Versionszeile {
    id: number;
    author: string | null;
    size: number;
    binary: boolean;
    created_at: string | null;
}

export interface Papierkorbzeile {
    id: number;
    original_path: string;
    type: 'file' | 'folder';
    size: number | null;
    deleted_at: string | null;
    deleted_by: { id: number; name: string } | null;
}

export interface Freigabe {
    id: number;
    path: string;
    url: string;
    status: 'active' | 'revoked' | 'expired';
    requires_password: boolean;
    expires_at: string | null;
    view_count: number;
}

/** Die Antwort war kein Erfolg. `status` 0 = das Netz kam nicht durch. */
export class VaultFehler extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly daten: unknown = null,
    ) {
        super(message);
        this.name = 'VaultFehler';
    }
}

/** Jemand anderes hat die Datei inzwischen geaendert. */
export class VaultKonflikt extends VaultFehler {
    constructor(
        public readonly aktuellerInhalt: string | null,
        public readonly aktuelleVersion: string,
    ) {
        super('Die Datei wurde inzwischen geaendert.', 409);
        this.name = 'VaultKonflikt';
    }
}

type Abfrage = Record<string, string | number | undefined>;

export function vaultApi(optionen: VaultApiOptionen) {
    const basis = optionen.basisUrl.replace(/\/+$/, '');
    const holen = optionen.fetch ?? ((...args: Parameters<typeof fetch>) => globalThis.fetch(...args));
    const token = () => (typeof optionen.csrfToken === 'function' ? optionen.csrfToken() : optionen.csrfToken);

    function adresse(pfad: string, abfrage: Abfrage = {}): string {
        const teile = Object.entries(abfrage)
            .filter(([, wert]) => wert !== undefined && wert !== '')
            .map(([schluessel, wert]) => `${encodeURIComponent(schluessel)}=${encodeURIComponent(String(wert))}`);

        return `${basis}/${pfad}${teile.length ? `?${teile.join('&')}` : ''}`;
    }

    async function anfrage<T>(methode: string, pfad: string, abfrage: Abfrage = {}, rumpf?: BodyInit | Record<string, unknown>): Promise<T> {
        const kopf: Record<string, string> = {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': token(),
        };
        let body: BodyInit | undefined;

        if (rumpf instanceof FormData) {
            body = rumpf;
        } else if (rumpf !== undefined) {
            kopf['Content-Type'] = 'application/json';
            body = JSON.stringify(rumpf);
        }

        let antwort: Response;
        try {
            antwort = await holen(adresse(pfad, abfrage), { method: methode, headers: kopf, credentials: 'same-origin', body });
        } catch {
            throw new VaultFehler('Keine Verbindung zum Server.', 0);
        }

        const daten: unknown = await antwort.json().catch(() => null);

        if (antwort.status === 409 && daten && typeof daten === 'object' && 'conflict' in daten) {
            const k = daten as unknown as { current_content: string | null; current_version: string };
            throw new VaultKonflikt(k.current_content, k.current_version);
        }

        if (!antwort.ok) {
            throw new VaultFehler(fehlertext(antwort.status, daten), antwort.status, daten);
        }

        return daten as T;
    }

    return {
        /** Fuer `<img src>`, Herunterladen und die HTML-Ansicht im iframe. */
        rohUrl: (scope: string, path: string, herunterladen = false) =>
            adresse('roh', { scope, path, download: herunterladen ? 1 : undefined }),

        baum: (scope: string) => anfrage<{ bereich: Bereichsangabe; baum: Baumeintrag[] }>('GET', 'baum', { scope }),
        datei: (scope: string, path: string) => anfrage<Dateiinhalt>('GET', 'datei', { scope, path }),
        anlegen: (scope: string, path: string, type: 'file' | 'folder', content = '') =>
            anfrage<{ ok: true; path: string; file_id?: string | null }>('POST', 'datei', { scope }, { path, type, content }),
        /** Mit `basisVersion` erkennt der Server fremde Aenderungen und wirft {@link VaultKonflikt}. */
        speichern: (scope: string, path: string, content: string, basisVersion?: string, erzwingen = false) =>
            anfrage<{ saved: true; version: string }>('PATCH', 'datei', { scope }, {
                path,
                content,
                base_version: basisVersion,
                force: erzwingen || undefined,
            }),
        loeschen: (scope: string, path: string) => anfrage<{ ok: true; trash_id: number }>('DELETE', 'datei', { scope }, { path }),
        verschieben: (scope: string, from: string, to: string) => anfrage<{ ok: true; path: string }>('POST', 'verschieben', { scope }, { from, to }),
        hochladen: (scope: string, datei: File, ordner = '') => {
            const formular = new FormData();
            formular.append('file', datei);
            formular.append('folder', ordner);

            return anfrage<{ path: string; file_id: string | null }>('POST', 'hochladen', { scope }, formular);
        },
        suche: (scope: string, q: string) => anfrage<Suchtreffer[]>('GET', 'suche', { scope, q }),

        versionen: (scope: string, path: string) => anfrage<{ versions: Versionszeile[] }>('GET', 'versionen', { scope, path }),
        versionInhalt: (id: number) => anfrage<{ id: number; path: string; binary: boolean; content: string | null }>('GET', `versionen/${id}`),
        versionWiederherstellen: (id: number) => anfrage<{ ok: true; path: string }>('POST', `versionen/${id}/wiederherstellen`),

        papierkorb: (scope: string) => anfrage<{ eintraege: Papierkorbzeile[] }>('GET', 'papierkorb', { scope }),
        papierkorbWiederherstellen: (id: number) => anfrage<{ ok: true; path: string }>('POST', `papierkorb/${id}/wiederherstellen`),
        papierkorbEntfernen: (id: number) => anfrage<{ ok: true }>('DELETE', `papierkorb/${id}`),

        freigaben: (scope: string, path: string) => anfrage<{ shares: Freigabe[] }>('GET', 'freigaben', { scope, path }),
        freigabeAnlegen: (scope: string, path: string, passwort?: string, tage?: number) =>
            anfrage<{ share: Freigabe }>('POST', 'freigaben', { scope }, { path, password: passwort || undefined, expires_in_days: tage }),
        freigabeZurueckziehen: (id: number) => anfrage<{ ok: true }>('DELETE', `freigaben/${id}`),
    };
}

export type VaultApi = ReturnType<typeof vaultApi>;

function fehlertext(status: number, daten: unknown): string {
    if (daten && typeof daten === 'object') {
        const d = daten as { error?: string; message?: string; errors?: Record<string, string[]> };
        const ersterFehler = d.errors ? Object.values(d.errors)[0]?.[0] : undefined;
        const text = d.error ?? ersterFehler ?? d.message;
        if (text) {
            return text;
        }
    }

    return (
        {
            403: 'Dafuer fehlen die Rechte.',
            404: 'Nicht gefunden.',
            413: 'Die Datei ist zu gross.',
            419: 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden.',
        }[status] ?? `Der Server hat mit ${status} geantwortet.`
    );
}
