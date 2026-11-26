import { FilePlus, FolderPlus, History, Search, Share2, Trash2, Upload } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { AnlegenDialog } from './AnlegenDialog';
import { Dateiansicht, type DateiansichtProps, type EditorSlot } from './Dateiansicht';
import { FreigabeDialog } from './FreigabeDialog';
import { Ordnerbaum, type OrdnerbaumProps } from './Ordnerbaum';
import { Papierkorbliste } from './Papierkorbliste';
import { fuerQuelle, istQuellenScope, type Bereichsangabe, type VaultApi } from './transport';
import type { Baumeintrag, Suchtreffer } from './typen';
import { Button } from './ui/button';
import { Input } from './ui/input';
import { Versionsliste } from './Versionsliste';

/**
 * Der Vault eines Bereichs als ein Baustein: Baum, Ansicht, Versionen,
 * Papierkorb, Freigaben, Suche.
 *
 * Seite, Layout und Navigation gehoeren dem Produkt; es bettet diesen
 * Baustein ein und reicht `api` (mit seiner Adresse und seinem CSRF-Token)
 * und `scope` herein. Welche Bereiche es gibt, entscheidet das Produkt —
 * der Baustein zeigt genau einen.
 */
export interface VaultBrowserProps {
    api: VaultApi;
    /** `private` = persoenlicher Bereich, sonst der Raum-Slug. */
    scope: string;
    /** Anfangs geoeffnete Datei, z.B. aus `?path=` der Seite. */
    startPfad?: string;
    /** Wenn das Produkt die Adresszeile mitfuehren will. */
    onPfadGewechselt?: (pfad: string) => void;
    renderEditor?: (slot: EditorSlot) => ReactNode;
    renderAnsicht?: (slot: { pfad: string; inhalt: string; rohUrl: string }) => ReactNode;
    /** Vorgabe: window.confirm. */
    bestaetigen?: (frage: string) => boolean;
    /** Office-Dateien im EuroOffice-Editor oeffnen. */
    euroOffice?: boolean;
    /** PDFs anmerken: PdfAnnotator aus `@peppermint-digital/vault/anmerken` einsetzen. */
    renderPdf?: DateiansichtProps['renderPdf'];
    /** Knoepfe des Produkts rechts in der Kopfzeile einer Datei (0.6.1), z. B. „Grossansicht". */
    renderDateiAktionen?: (slot: { pfad: string; scope: string; darfSchreiben: boolean }) => ReactNode;
    /** Eine Datei wurde geoeffnet — per Klick, Suche, Anlegen oder ueber `startPfad` (0.6.1). */
    onDateiGeoeffnet?: (pfad: string) => void;
    /** Etwas rechts an jedem Eintrag im Baum (0.6.1), z. B. Koepfe der Bearbeitenden. */
    renderBaumMarke?: OrdnerbaumProps['renderBaumMarke'];
}

type Seitenleiste = 'ansicht' | 'versionen' | 'papierkorb';
type Dialogzustand = { art: 'datei' | 'ordner' | 'umbenennen'; ausgang: string } | null;

export function VaultBrowser({ api: grundApi, scope, startPfad = '', onPfadGewechselt, renderEditor, renderAnsicht, bestaetigen, euroOffice, renderPdf, renderDateiAktionen, onDateiGeoeffnet, renderBaumMarke }: VaultBrowserProps) {
    // Externe Quelle (0.6.0): dieselben Bausteine, aber ueber `quelle/*`,
    // Baum ebenenweise, ohne Versionen, Papierkorb, Freigaben und Suche.
    const quelle = istQuellenScope(scope);
    const api = useMemo(() => (quelle ? fuerQuelle(grundApi) : grundApi), [grundApi, quelle]);
    const [nichtErreichbar, setNichtErreichbar] = useState(false);
    const [bereich, setBereich] = useState<Bereichsangabe | null>(null);
    const [baum, setBaum] = useState<Baumeintrag[] | null>(null);
    const [pfad, setPfad] = useState(startPfad);
    const [fehler, setFehler] = useState<string | null>(null);
    const [ansicht, setAnsicht] = useState<Seitenleiste>('ansicht');
    const [dialog, setDialog] = useState<Dialogzustand>(null);
    const [freigabeOffen, setFreigabeOffen] = useState(false);
    const [suche, setSuche] = useState('');
    const [treffer, setTreffer] = useState<Suchtreffer[] | null>(null);
    const [ladeschluessel, setLadeschluessel] = useState(0);
    const hochladenFeld = useRef<HTMLInputElement>(null);
    const hochladenOrdner = useRef('');

    const neuLaden = useCallback(() => setLadeschluessel((n) => n + 1), []);
    const frage = bestaetigen ?? ((f: string) => globalThis.confirm(f));

    useEffect(() => {
        let ab = false;
        if (quelle) {
            grundApi
                .quelleEbene(scope)
                .then((d) => {
                    if (ab) return;
                    setNichtErreichbar(!d.erreichbar);
                    setBereich(d.bereich ?? { slug: scope, label: scope.slice('extern:'.length), persoenlich: false, darfSchreiben: false });
                    setBaum(d.eintraege);
                    setFehler(null);
                })
                .catch((e: Error) => !ab && setFehler(e.message));
        } else {
            grundApi
                .baum(scope)
                .then((d) => {
                    if (!ab) {
                        setBereich(d.bereich);
                        setBaum(d.baum);
                        setFehler(null);
                    }
                })
                .catch((e: Error) => !ab && setFehler(e.message));
        }
        return () => {
            ab = true;
        };
    }, [grundApi, quelle, scope, ladeschluessel]);

    const oeffnen = useCallback(
        (neuerPfad: string) => {
            setPfad(neuerPfad);
            setAnsicht('ansicht');
            onPfadGewechselt?.(neuerPfad);
        },
        [onPfadGewechselt],
    );

    const ebeneLaden = useCallback(
        (ordner: string) => {
            grundApi
                .quelleEbene(scope, ordner)
                .then((d) => setBaum((vorher) => (vorher === null ? vorher : einsetzen(vorher, ordner, d.eintraege))))
                .catch((e: Error) => setFehler(e.message));
        },
        [grundApi, scope],
    );

    async function ausfuehren(arbeit: () => Promise<unknown>) {
        try {
            await arbeit();
            neuLaden();
        } catch (e) {
            setFehler((e as Error).message);
        }
    }

    async function dialogBestaetigt(neuerPfad: string) {
        if (dialog === null) {
            return;
        }
        // Fehler hier bewusst NICHT abfangen: Der Dialog zeigt sie und bleibt offen.
        if (dialog.art === 'umbenennen') {
            await api.verschieben(scope, dialog.ausgang, neuerPfad);
            if (pfad === dialog.ausgang || pfad.startsWith(dialog.ausgang + '/')) {
                oeffnen(neuerPfad + pfad.slice(dialog.ausgang.length));
            }
        } else {
            const d = await api.anlegen(scope, neuerPfad, dialog.art === 'ordner' ? 'folder' : 'file');
            if (dialog.art === 'datei') {
                oeffnen(d.path);
            }
        }
        neuLaden();
    }

    function loeschen(ziel: string, typ: 'file' | 'folder') {
        const wohin = typ === 'folder' ? 'Der Ordner landet samt Inhalt im Papierkorb.' : 'Die Datei landet im Papierkorb.';
        if (!frage(`„${ziel}" löschen? ${wohin}`)) {
            return;
        }
        void ausfuehren(async () => {
            await api.loeschen(scope, ziel);
            if (pfad === ziel || pfad.startsWith(ziel + '/')) {
                oeffnen('');
            }
        });
    }

    function hochladenStarten(ordner: string) {
        hochladenOrdner.current = ordner;
        hochladenFeld.current?.click();
    }

    async function suchen(e: FormEvent) {
        e.preventDefault();
        if (suche.trim() === '') {
            setTreffer(null);
            return;
        }
        try {
            setTreffer(await api.suche(scope, suche.trim()));
        } catch (fehlerObjekt) {
            setFehler((fehlerObjekt as Error).message);
        }
    }

    const darfSchreiben = bereich?.darfSchreiben ?? false;
    const istDatei = pfad !== '' && !(baum && istOrdnerIn(baum, pfad));

    const gemeldet = useRef('');
    useEffect(() => {
        if (baum !== null && istDatei && gemeldet.current !== pfad) {
            gemeldet.current = pfad;
            onDateiGeoeffnet?.(pfad);
        }
    }, [baum, istDatei, pfad, onDateiGeoeffnet]);

    return (
        <div className="flex h-full min-h-[60vh] flex-col gap-3 md:flex-row">
            <aside className="flex w-full shrink-0 flex-col gap-2 md:w-72">
                <div className="flex items-center justify-between gap-2">
                    <h2 className="truncate text-sm font-semibold">{bereich?.label ?? '…'}</h2>
                    {darfSchreiben && (
                        <div className="flex gap-1">
                            <Button size="icon" variant="ghost" aria-label="Neue Datei" title="Neue Datei" onClick={() => setDialog({ art: 'datei', ausgang: '' })}>
                                <FilePlus />
                            </Button>
                            <Button size="icon" variant="ghost" aria-label="Neuer Ordner" title="Neuer Ordner" onClick={() => setDialog({ art: 'ordner', ausgang: '' })}>
                                <FolderPlus />
                            </Button>
                            <Button size="icon" variant="ghost" aria-label="Datei hochladen" title="Datei hochladen" onClick={() => hochladenStarten('')}>
                                <Upload />
                            </Button>
                        </div>
                    )}
                </div>

                {!quelle && (
                    <form onSubmit={suchen} className="relative">
                        <Search className="pointer-events-none absolute top-2.5 left-2 size-4 text-muted-foreground" />
                        <Input value={suche} onChange={(e) => setSuche(e.target.value)} placeholder="Suchen…" className="pl-8" aria-label="Im Bereich suchen" />
                    </form>
                )}

                {quelle && nichtErreichbar && (
                    <p role="status" className="rounded-md border p-2 text-sm text-muted-foreground">
                        Diese Quelle ist gerade nicht erreichbar. Die Dateien sind nicht weg — der Speicher antwortet nur nicht.
                    </p>
                )}

                {fehler !== null && (
                    <p role="alert" className="rounded-md border border-destructive/40 p-2 text-sm text-destructive">
                        {fehler}
                    </p>
                )}

                <div className="min-h-0 flex-1 overflow-auto rounded-md border">
                    {treffer !== null ? (
                        <ul className="divide-y" aria-label="Suchergebnisse">
                            {treffer.length === 0 && <li className="p-3 text-sm text-muted-foreground">Nichts gefunden.</li>}
                            {treffer.map((t) => (
                                <li key={t.path}>
                                    <button type="button" className="w-full p-2 text-left text-sm hover:bg-accent" onClick={() => oeffnen(t.path)}>
                                        <div className="truncate font-medium">{t.title}</div>
                                        {t.excerpt && <div className="line-clamp-2 text-xs text-muted-foreground">{t.excerpt}</div>}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    ) : baum === null ? (
                        <p className="p-3 text-sm text-muted-foreground">Wird geladen…</p>
                    ) : (
                        <Ordnerbaum
                            baum={baum}
                            aktuellerPfad={pfad}
                            speicherSchluessel={scope}
                            nurLesen={!darfSchreiben}
                            onWaehlen={oeffnen}
                            onNeueDatei={(o) => setDialog({ art: 'datei', ausgang: o })}
                            onNeuerOrdner={(o) => setDialog({ art: 'ordner', ausgang: o })}
                            onHochladen={hochladenStarten}
                            onUmbenennen={(p) => setDialog({ art: 'umbenennen', ausgang: p })}
                            onLoeschen={loeschen}
                            onHerunterladen={(p) => globalThis.open(api.rohUrl(scope, p, true), '_blank')}
                            vorschauUrl={(p) => (!quelle && /\.(jpe?g|png|gif|webp|bmp|svg)$/i.test(p) ? api.vorschauUrl(scope, p) : null)}
                            onOrdnerOeffnen={quelle ? ebeneLaden : undefined}
                            renderBaumMarke={renderBaumMarke}
                        />
                    )}
                </div>

                {quelle ? (
                    <p className="px-2 text-xs text-muted-foreground">Externe Quelle: keine Versionen, kein Papierkorb — Gelöschtes ist sofort weg.</p>
                ) : (
                    <Button variant="ghost" size="sm" className="justify-start" onClick={() => setAnsicht('papierkorb')}>
                        <Trash2 /> Papierkorb
                    </Button>
                )}
            </aside>

            <section className="min-w-0 flex-1 rounded-md border">
                {ansicht === 'papierkorb' ? (
                    <div className="grid gap-3 p-4">
                        <h2 className="text-lg font-medium">Papierkorb</h2>
                        <Papierkorbliste
                            api={api}
                            scope={scope}
                            darfAendern={darfSchreiben}
                            bestaetigen={bestaetigen}
                            onWiederhergestellt={(p) => {
                                neuLaden();
                                oeffnen(p);
                            }}
                        />
                    </div>
                ) : !istDatei ? (
                    <p className="p-6 text-sm text-muted-foreground">Links eine Datei wählen.</p>
                ) : (
                    <>
                        <div className="flex gap-1 border-b px-2 py-1">
                            <Button size="sm" variant={ansicht === 'ansicht' ? 'secondary' : 'ghost'} onClick={() => setAnsicht('ansicht')}>
                                Datei
                            </Button>
                            {!quelle && (
                                <Button size="sm" variant={ansicht === 'versionen' ? 'secondary' : 'ghost'} onClick={() => setAnsicht('versionen')}>
                                    <History /> Versionen
                                </Button>
                            )}
                            {darfSchreiben && !quelle && (
                                <Button size="sm" variant="ghost" onClick={() => setFreigabeOffen(true)}>
                                    <Share2 /> Freigeben
                                </Button>
                            )}
                            {renderDateiAktionen && <div className="ml-auto flex items-center gap-1">{renderDateiAktionen({ pfad, scope, darfSchreiben })}</div>}
                        </div>
                        {ansicht === 'versionen' ? (
                            <div className="p-4">
                                <Versionsliste api={api} scope={scope} pfad={pfad} darfWiederherstellen={darfSchreiben} onWiederhergestellt={() => setAnsicht('ansicht')} />
                            </div>
                        ) : (
                            <Dateiansicht
                                key={`${scope}:${pfad}:${ladeschluessel}`}
                                api={api}
                                scope={scope}
                                pfad={pfad}
                                darfSchreiben={darfSchreiben}
                                renderEditor={renderEditor}
                                renderAnsicht={renderAnsicht}
                                euroOffice={euroOffice && !quelle}
                                renderPdf={quelle ? undefined : renderPdf}
                                epubLesefassungVorhanden={(p) => baum !== null && istDateiIn(baum, p)}
                            />
                        )}
                        <FreigabeDialog api={api} scope={scope} pfad={pfad} offen={freigabeOffen} onSchliessen={() => setFreigabeOffen(false)} />
                    </>
                )}
            </section>

            <AnlegenDialog
                offen={dialog !== null}
                art={dialog?.art ?? 'datei'}
                ausgang={dialog?.ausgang ?? ''}
                onSchliessen={() => setDialog(null)}
                onBestaetigen={dialogBestaetigt}
                formate={euroOffice ? ['md', 'docx', 'xlsx', 'pptx'] : ['md']}
                vorhanden={namenIn(baum, dialog === null ? '' : dialog.art === 'umbenennen' ? ordnerVon(dialog.ausgang) : dialog.ausgang)}
                bereichName={bereich?.label}
            />

            <input
                ref={hochladenFeld}
                type="file"
                className="hidden"
                aria-hidden
                onChange={(e) => {
                    const datei = e.target.files?.[0];
                    e.target.value = '';
                    if (datei) {
                        void ausfuehren(async () => {
                            const d = await api.hochladen(scope, datei, hochladenOrdner.current);
                            oeffnen(d.path);
                        });
                    }
                }}
            />
        </div>
    );
}

/** Namen im Ordner `ordner` ('' = oberste Ebene), fuer den Hinweis „gibt es schon". */
function namenIn(baum: Baumeintrag[] | null, ordner: string): string[] {
    let ebene = baum ?? [];
    for (const teil of ordner.split('/').filter(Boolean)) {
        ebene = ebene.find((e) => e.type === 'folder' && e.name === teil)?.children ?? [];
    }
    return ebene.map((e) => e.name);
}

function ordnerVon(pfad: string): string {
    const i = pfad.lastIndexOf('/');
    return i === -1 ? '' : pfad.slice(0, i);
}

/** Die geladene Ebene eines Ordners in den Baum haengen (ebenenweise Quellen). */
function einsetzen(baum: Baumeintrag[], ordner: string, kinder: Baumeintrag[]): Baumeintrag[] {
    return baum.map((e) => {
        if (e.type !== 'folder') return e;
        if (e.path === ordner) return { ...e, children: kinder };
        if (e.children && ordner.startsWith(e.path + '/')) return { ...e, children: einsetzen(e.children, ordner, kinder) };
        return e;
    });
}

function istDateiIn(baum: Baumeintrag[], pfad: string): boolean {
    for (const k of baum) {
        if (k.path === pfad) {
            return k.type === 'file';
        }
        if (k.type === 'folder' && pfad.startsWith(k.path + '/') && k.children) {
            return istDateiIn(k.children, pfad);
        }
    }

    return false;
}

function istOrdnerIn(baum: Baumeintrag[], pfad: string): boolean {
    for (const k of baum) {
        if (k.path === pfad) {
            return k.type === 'folder';
        }
        if (k.type === 'folder' && pfad.startsWith(k.path + '/') && k.children) {
            return istOrdnerIn(k.children, pfad);
        }
    }

    return false;
}

export default VaultBrowser;
