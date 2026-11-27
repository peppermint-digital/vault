import { Download, Pencil, Save, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { EpubAnnotator } from './anmerkungen/EpubAnnotator';
import { EuroOfficeEditor } from './EuroOfficeEditor';
import { VaultKonflikt, type VaultApi } from './transport';
import { Button } from './ui/button';

/**
 * Eine Datei ansehen und bearbeiten.
 *
 * Gespeichert wird immer mit der Fassung, die geladen wurde. Hat jemand
 * anderes die Datei inzwischen geaendert, antwortet der Server 409 — und die
 * Ansicht FRAGT, statt still zu ueberschreiben oder still zu verwerfen.
 *
 * Editor und Lese-Darstellung sind Slots: Das Produkt setzt dort seinen
 * Markdown-Editor und seine Markdown-Ansicht ein. Ohne sie gibt es ein
 * Textfeld und vorformatierten Text — benutzbar, nur schlicht.
 */
export interface EditorSlot {
    pfad: string;
    wert: string;
    onAendern: (wert: string) => void;
    /** Bereich der Datei (0.6.1) — z. B. fuer einen Editor, der selbst Bilder einfuegt. */
    scope: string;
    darfSchreiben: boolean;
}

/**
 * Was die Ansicht des Produkts bekommt (`renderAnsicht`). Seit 0.7.3 (aibrain-e1,
 * Keeper A60) zusaetzlich `scope`, `oeffnen` und `speichern`, rein additiv:
 * - `oeffnen(pfad)` oeffnet eine Datei im Browser wie ein Klick im Baum (Wikilinks).
 * - `speichern(inhalt)` speichert ueber denselben Weg wie der Editor: mit der
 *   geladenen Version als Basis; bei 409 erscheint der Konfliktkasten (der Inhalt
 *   wird zum Entwurf) und das Promise lehnt ab. Ohne Schreibrecht `undefined`.
 */
export interface AnsichtSlot {
    pfad: string;
    inhalt: string;
    rohUrl: string;
    scope: string;
    oeffnen: (pfad: string) => void;
    speichern?: (inhalt: string) => Promise<void>;
    /**
     * Einen [[Wikilink]] (oder `Name`, `ordner/Name`, `../Name`) aus dieser Datei heraus
     * oeffnen - aufgeloest nach derselben Regel wie Graph und „Verweise hierher" (0.7.6).
     * `false`: nicht gefunden, das Produkt zeigt einen Hinweis.
     */
    verweisOeffnen: (link: string) => boolean;
}

export interface AutoSpeichern {
    /** Welche Dateien: Muster oder Entscheidung je Pfad. Vorgabe: Zeichnungen (`.excalidraw`). */
    dateien?: RegExp | ((pfad: string) => boolean);
    /**
     * Mit Schreibrecht sofort im Editor oeffnen, ohne „Bearbeiten" und ohne „Verwerfen"
     * (0.7.5, Vorgabe true) - so war eine Zeichnung in AI Brain immer bearbeitbar.
     */
    direkt?: boolean;
    nachMs?: number;
}

export interface DateiansichtProps {
    api: VaultApi;
    scope: string;
    pfad: string;
    darfSchreiben: boolean;
    renderEditor?: (slot: EditorSlot) => ReactNode;
    renderAnsicht?: (slot: AnsichtSlot) => ReactNode;
    onGespeichert?: (pfad: string) => void;
    /** Eine andere Datei oeffnen (0.7.3) - der VaultBrowser reicht sein `oeffnen` herein. */
    onOeffnen?: (pfad: string) => void;
    /** Einen Wikilink aus dieser Datei oeffnen (0.7.6) - der VaultBrowser loest ihn im Baum auf. */
    onVerweis?: (link: string) => boolean;
    /**
     * Automatisch speichern, waehrend bearbeitet wird (0.7.4, Vorgabe aus). Fuer Dateien,
     * deren Pfad zu `dateien` passt (Vorgabe: Zeichnungen), `nachMs` nach der letzten
     * Aenderung (Vorgabe 2000) - ueber denselben Weg wie „Speichern", der Editor bleibt
     * offen. Konflikt haelt es an. Beim Verlassen mit ungespeicherter Aenderung sofort.
     */
    autoSpeichern?: AutoSpeichern;
    /** Office-Dateien im EuroOffice-Editor oeffnen (Server muss eingerichtet sein). */
    euroOffice?: boolean;
    /**
     * PDFs lesen und anmerken. Das Produkt setzt hier den PdfAnnotator aus
     * `@peppermint-digital/vault/anmerken` ein (braucht pdf.js); ohne Slot nur Herunterladen.
     */
    renderPdf?: (slot: { api: VaultApi; scope: string; pfad: string }) => ReactNode;
    /** EPUB anmerken, wenn die Lesefassung `<buch>.html` daneben liegt. */
    epubLesefassungVorhanden?: (pfad: string) => boolean;
}

const TEXT = /\.(md|markdown|txt|html?|json|csv|excalidraw)$/i;
const BILD = /\.(png|jpe?g|gif|webp|svg)$/i;
export const OFFICE = /\.(docx?|xlsx?|pptx?|odt|ods|odp|rtf)$/i;

type Zustand =
    | { art: 'laedt' }
    | { art: 'fehler'; text: string }
    | { art: 'da'; inhalt: string; version: string };

export function Dateiansicht({
    api,
    scope,
    pfad,
    darfSchreiben,
    renderEditor,
    renderAnsicht,
    onGespeichert,
    onOeffnen,
    onVerweis,
    autoSpeichern,
    euroOffice = false,
    renderPdf,
    epubLesefassungVorhanden,
}: DateiansichtProps) {
    const istText = TEXT.test(pfad);
    const [zustand, setZustand] = useState<Zustand>({ art: 'laedt' });
    const [entwurf, setEntwurf] = useState<string | null>(null);
    const [konflikt, setKonflikt] = useState<VaultKonflikt | null>(null);
    const [meldung, setMeldung] = useState<string | null>(null);
    const [speichert, setSpeichert] = useState(false);

    useEffect(() => {
        setEntwurf(null);
        setKonflikt(null);
        setMeldung(null);

        if (!istText) {
            return;
        }

        let ab = false;
        setZustand({ art: 'laedt' });
        api.datei(scope, pfad)
            .then((d) => !ab && setZustand({ art: 'da', inhalt: d.content, version: d.version }))
            .catch((e: Error) => !ab && setZustand({ art: 'fehler', text: e.message }));

        return () => {
            ab = true;
        };
    }, [api, scope, pfad, istText]);

    const rohUrl = api.rohUrl(scope, pfad);
    const name = pfad.split('/').pop() ?? pfad;

    /**
     * Der eine Speicherweg - fuer den Editor (Entwurf) und seit 0.7.3 fuer die Ansicht
     * des Produkts (z.B. ein angehaktes Kontrollkaestchen). Liefert, ob gespeichert wurde.
     */
    async function speichernInhalt(inhalt: string, erzwingen: boolean): Promise<'ok' | 'konflikt' | 'fehler'> {
        if (zustand.art !== 'da') {
            return 'fehler';
        }
        setSpeichert(true);
        setMeldung(null);
        try {
            const d = await api.speichern(scope, pfad, inhalt, zustand.version, erzwingen);
            setZustand({ art: 'da', inhalt, version: d.version });
            setEntwurf(null);
            setKonflikt(null);
            setMeldung('Gespeichert.');
            onGespeichert?.(pfad);
            return 'ok';
        } catch (e) {
            if (e instanceof VaultKonflikt) {
                // Der eigene Stand wird zum Entwurf, damit beide Knoepfe im Kasten wirken.
                setEntwurf(inhalt);
                setKonflikt(e);
                return 'konflikt';
            }
            setMeldung((e as Error).message);
            return 'fehler';
        } finally {
            setSpeichert(false);
        }
    }

    // Automatisch speichern (0.7.4): der Editor bleibt offen, der Entwurf bleibt.
    const autoDateien = autoSpeichern?.dateien ?? /\.excalidraw$/i;
    const autoAn = darfSchreiben && autoSpeichern !== undefined && (typeof autoDateien === 'function' ? autoDateien(pfad) : autoDateien.test(pfad));
    const stand = useRef({ entwurf, zustand, konflikt, speichert });
    stand.current = { entwurf, zustand, konflikt, speichert };

    const autoJetzt = useCallback(async () => {
        const { entwurf: e, zustand: z, konflikt: k } = stand.current;
        if (e === null || z.art !== 'da' || e === z.inhalt || k !== null) {
            return;
        }
        setSpeichert(true);
        setMeldung('Speichert…');
        try {
            const d = await api.speichern(scope, pfad, e, z.version, false);
            setZustand({ art: 'da', inhalt: e, version: d.version });
            setMeldung(`Automatisch gespeichert ${new Date().toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })}`);
            onGespeichert?.(pfad);
        } catch (fehler) {
            if (fehler instanceof VaultKonflikt) {
                setKonflikt(fehler);
            } else {
                setMeldung((fehler as Error).message);
            }
        } finally {
            setSpeichert(false);
        }
    }, [api, scope, pfad, onGespeichert]);

    // Direkt bearbeitbar (0.7.5): sobald geladen, liegt der Inhalt im Editor - auch wieder nach
    // „Speichern" oder „Andere Fassung uebernehmen".
    const direkt = autoAn && (autoSpeichern?.direkt ?? true);
    useEffect(() => {
        if (direkt && entwurf === null && zustand.art === 'da') {
            setEntwurf(zustand.inhalt);
        }
    }, [direkt, entwurf, zustand]);

    // Nichts verlieren beim Schliessen des Tabs: Browser fragt nach, solange Ungespeichertes da ist.
    const ungespeichert = autoAn && entwurf !== null && zustand.art === 'da' && entwurf !== zustand.inhalt;
    useEffect(() => {
        if (!ungespeichert) {
            return;
        }
        const warnen = (e: BeforeUnloadEvent) => {
            e.preventDefault();
            e.returnValue = '';
        };
        window.addEventListener('beforeunload', warnen);
        return () => window.removeEventListener('beforeunload', warnen);
    }, [ungespeichert]);

    useEffect(() => {
        if (!autoAn || entwurf === null || zustand.art !== 'da' || entwurf === zustand.inhalt || konflikt !== null || speichert) {
            return;
        }
        const t = setTimeout(() => void autoJetzt(), autoSpeichern?.nachMs ?? 2000);
        return () => clearTimeout(t);
    }, [autoAn, entwurf, zustand, konflikt, speichert, autoJetzt, autoSpeichern?.nachMs]);

    // Beim Verlassen (Dateiwechsel, Schliessen) nichts verlieren: offene Aenderung sofort senden.
    useEffect(() => {
        if (!autoAn) {
            return;
        }
        return () => {
            const { entwurf: e, zustand: z, konflikt: k } = stand.current;
            if (e !== null && z.art === 'da' && e !== z.inhalt && k === null) {
                void api.speichern(scope, pfad, e, z.version, false).catch(() => {});
            }
        };
    }, [autoAn, api, scope, pfad]);

    async function speichern(erzwingen = false) {
        if (entwurf === null) {
            return;
        }
        await speichernInhalt(entwurf, erzwingen);
    }

    async function ansichtSpeichern(inhalt: string): Promise<void> {
        const ergebnis = await speichernInhalt(inhalt, false);
        if (ergebnis === 'konflikt') {
            throw new Error('Jemand hat diese Datei geändert - bitte im Hinweis entscheiden.');
        }
        if (ergebnis === 'fehler') {
            throw new Error('Speichern fehlgeschlagen.');
        }
    }

    function fremdeUebernehmen() {
        if (konflikt === null) {
            return;
        }
        setZustand({ art: 'da', inhalt: konflikt.aktuellerInhalt ?? '', version: konflikt.aktuelleVersion });
        setEntwurf(null);
        setKonflikt(null);
    }

    if (!istText && renderPdf && /\.pdf$/i.test(pfad)) {
        return <div className="flex h-[80vh] min-h-0 flex-col">{renderPdf({ api, scope, pfad })}</div>;
    }

    const lesefassung = pfad.replace(/\.epub$/i, '.html');
    if (!istText && /\.epub$/i.test(pfad) && epubLesefassungVorhanden?.(lesefassung)) {
        return (
            <div className="flex h-[80vh] min-h-0 flex-col">
                <EpubAnnotator api={api} scope={scope} path={pfad} htmlUrl={api.rohUrl(scope, lesefassung)} />
            </div>
        );
    }

    if (!istText) {
        return (
            <div className="grid gap-3 p-4">
                <h2 className="truncate text-lg font-medium">{name}</h2>
                {euroOffice && OFFICE.test(pfad) && <EuroOfficeEditor api={api} scope={scope} pfad={pfad} />}
                {BILD.test(pfad) && <img src={rohUrl} alt={name} className="max-h-[70vh] max-w-full rounded-md border object-contain" />}
                <div>
                    <Button asChild variant="outline">
                        <a href={api.rohUrl(scope, pfad, true)}>
                            <Download /> Herunterladen
                        </a>
                    </Button>
                </div>
            </div>
        );
    }

    if (zustand.art === 'laedt') {
        return <p className="p-4 text-sm text-muted-foreground">Wird geladen…</p>;
    }
    if (zustand.art === 'fehler') {
        return <p role="alert" className="p-4 text-sm text-destructive">{zustand.text}</p>;
    }

    const bearbeitet = entwurf !== null;

    return (
        <div className="grid gap-3 p-4">
            <div className="flex items-center gap-2">
                <h2 className="min-w-0 flex-1 truncate text-lg font-medium">{name}</h2>
                {darfSchreiben && !bearbeitet && (
                    <Button size="sm" variant="outline" onClick={() => setEntwurf(zustand.inhalt)}>
                        <Pencil /> Bearbeiten
                    </Button>
                )}
                {bearbeitet && (
                    <>
                        {!direkt && (
                            <Button size="sm" variant="ghost" onClick={() => setEntwurf(null)} disabled={speichert}>
                                <X /> Verwerfen
                            </Button>
                        )}
                        <Button size="sm" onClick={() => speichern()} disabled={speichert}>
                            <Save /> Speichern
                        </Button>
                    </>
                )}
            </div>

            {konflikt !== null && (
                <div role="alert" className="grid gap-2 rounded-md border border-destructive/50 bg-destructive/5 p-3 text-sm">
                    <p>Jemand hat diese Datei geändert, während du sie bearbeitet hast.</p>
                    <div className="flex flex-wrap gap-2">
                        <Button size="sm" variant="destructive" onClick={() => speichern(true)}>
                            Meine Fassung trotzdem speichern
                        </Button>
                        <Button size="sm" variant="outline" onClick={fremdeUebernehmen}>
                            Andere Fassung übernehmen (meine verwerfen)
                        </Button>
                    </div>
                </div>
            )}

            {meldung !== null && <p className="text-sm text-muted-foreground" role="status">{meldung}</p>}

            {bearbeitet ? (
                renderEditor ? (
                    renderEditor({ pfad, wert: entwurf, onAendern: setEntwurf, scope, darfSchreiben })
                ) : (
                    <textarea
                        className="min-h-[50vh] w-full rounded-md border bg-background p-3 font-mono text-sm"
                        value={entwurf}
                        onChange={(e) => setEntwurf(e.target.value)}
                        aria-label={`Inhalt von ${name}`}
                    />
                )
            ) : renderAnsicht ? (
                renderAnsicht({
                    pfad,
                    inhalt: zustand.inhalt,
                    rohUrl,
                    scope,
                    oeffnen: (ziel) => onOeffnen?.(ziel),
                    verweisOeffnen: (link) => onVerweis?.(link) ?? false,
                    speichern: darfSchreiben ? ansichtSpeichern : undefined,
                })
            ) : (
                <pre className="overflow-auto rounded-md border bg-muted/30 p-3 text-sm whitespace-pre-wrap">{zustand.inhalt}</pre>
            )}
        </div>
    );
}

export default Dateiansicht;
