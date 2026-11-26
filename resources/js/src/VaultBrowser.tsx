import { FilePlus, FolderPlus, History, Search, Share2, Trash2, Upload } from 'lucide-react';
import { useCallback, useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { AnlegenDialog } from './AnlegenDialog';
import { Dateiansicht, type EditorSlot } from './Dateiansicht';
import { FreigabeDialog } from './FreigabeDialog';
import { Ordnerbaum } from './Ordnerbaum';
import { Papierkorbliste } from './Papierkorbliste';
import type { Bereichsangabe, VaultApi } from './transport';
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
}

type Seitenleiste = 'ansicht' | 'versionen' | 'papierkorb';
type Dialogzustand = { art: 'datei' | 'ordner' | 'umbenennen'; ausgang: string } | null;

export function VaultBrowser({ api, scope, startPfad = '', onPfadGewechselt, renderEditor, renderAnsicht, bestaetigen, euroOffice }: VaultBrowserProps) {
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
        api.baum(scope)
            .then((d) => {
                if (!ab) {
                    setBereich(d.bereich);
                    setBaum(d.baum);
                    setFehler(null);
                }
            })
            .catch((e: Error) => !ab && setFehler(e.message));
        return () => {
            ab = true;
        };
    }, [api, scope, ladeschluessel]);

    const oeffnen = useCallback(
        (neuerPfad: string) => {
            setPfad(neuerPfad);
            setAnsicht('ansicht');
            onPfadGewechselt?.(neuerPfad);
        },
        [onPfadGewechselt],
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

                <form onSubmit={suchen} className="relative">
                    <Search className="pointer-events-none absolute top-2.5 left-2 size-4 text-muted-foreground" />
                    <Input value={suche} onChange={(e) => setSuche(e.target.value)} placeholder="Suchen…" className="pl-8" aria-label="Im Bereich suchen" />
                </form>

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
                        />
                    )}
                </div>

                <Button variant="ghost" size="sm" className="justify-start" onClick={() => setAnsicht('papierkorb')}>
                    <Trash2 /> Papierkorb
                </Button>
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
                            <Button size="sm" variant={ansicht === 'versionen' ? 'secondary' : 'ghost'} onClick={() => setAnsicht('versionen')}>
                                <History /> Versionen
                            </Button>
                            {darfSchreiben && (
                                <Button size="sm" variant="ghost" onClick={() => setFreigabeOffen(true)}>
                                    <Share2 /> Freigeben
                                </Button>
                            )}
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
                                euroOffice={euroOffice}
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
