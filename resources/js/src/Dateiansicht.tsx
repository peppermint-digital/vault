import { Download, Pencil, Save, X } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
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
}

export interface DateiansichtProps {
    api: VaultApi;
    scope: string;
    pfad: string;
    darfSchreiben: boolean;
    renderEditor?: (slot: EditorSlot) => ReactNode;
    renderAnsicht?: (slot: { pfad: string; inhalt: string; rohUrl: string }) => ReactNode;
    onGespeichert?: (pfad: string) => void;
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
const OFFICE = /\.(docx?|xlsx?|pptx?|odt|ods|odp|rtf)$/i;

type Zustand =
    | { art: 'laedt' }
    | { art: 'fehler'; text: string }
    | { art: 'da'; inhalt: string; version: string };

export function Dateiansicht({ api, scope, pfad, darfSchreiben, renderEditor, renderAnsicht, onGespeichert, euroOffice = false, renderPdf, epubLesefassungVorhanden }: DateiansichtProps) {
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

    async function speichern(erzwingen = false) {
        if (entwurf === null || zustand.art !== 'da') {
            return;
        }
        setSpeichert(true);
        setMeldung(null);
        try {
            const d = await api.speichern(scope, pfad, entwurf, zustand.version, erzwingen);
            setZustand({ art: 'da', inhalt: entwurf, version: d.version });
            setEntwurf(null);
            setKonflikt(null);
            setMeldung('Gespeichert.');
            onGespeichert?.(pfad);
        } catch (e) {
            if (e instanceof VaultKonflikt) {
                setKonflikt(e);
            } else {
                setMeldung((e as Error).message);
            }
        } finally {
            setSpeichert(false);
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
                        <Button size="sm" variant="ghost" onClick={() => setEntwurf(null)} disabled={speichert}>
                            <X /> Verwerfen
                        </Button>
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
                    renderEditor({ pfad, wert: entwurf, onAendern: setEntwurf })
                ) : (
                    <textarea
                        className="min-h-[50vh] w-full rounded-md border bg-background p-3 font-mono text-sm"
                        value={entwurf}
                        onChange={(e) => setEntwurf(e.target.value)}
                        aria-label={`Inhalt von ${name}`}
                    />
                )
            ) : renderAnsicht ? (
                renderAnsicht({ pfad, inhalt: zustand.inhalt, rohUrl })
            ) : (
                <pre className="overflow-auto rounded-md border bg-muted/30 p-3 text-sm whitespace-pre-wrap">{zustand.inhalt}</pre>
            )}
        </div>
    );
}

export default Dateiansicht;
