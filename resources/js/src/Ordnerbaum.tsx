import { ChevronDown, ChevronRight, Download, Eye, FilePlus, FileText, Folder, FolderOpen, FolderPlus, FolderUp, MoreVertical, Pencil, Trash2, Upload } from 'lucide-react';
import { useCallback, useEffect, useState, type ReactNode } from 'react';
import type { Baumeintrag } from './typen';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from './ui/dropdown-menu';
import { cn } from './ui/utils';

/**
 * Der Ordnerbaum (AI Brain `components/notes/folder-tree.tsx`).
 *
 * Abweichungen:
 * - Aktionen hinter einem „⋮"-Knopf statt Rechtsklick. Rechtsklick gibt es
 *   auf dem Telefon nicht, und dort wird der Firmen-Vault auch benutzt.
 * - Der Aufklapp-Zustand liegt unter einem Schluessel JE BEREICH. In AI Brain
 *   teilten sich alle Bereiche einen, und ein Ordner `Projekte` war dann in
 *   jedem Bereich aufgeklappt, sobald er es in einem war.
 * - Ohne Schreibrecht (`nurLesen`) gibt es keine aendernden Eintraege.
 */
export interface OrdnerbaumProps {
    baum: Baumeintrag[];
    aktuellerPfad: string;
    /** Schluessel fuer den gemerkten Aufklapp-Zustand, z.B. der Bereichs-Slug. */
    speicherSchluessel: string;
    onWaehlen: (pfad: string) => void;
    onLesen?: (pfad: string) => void;
    onNeueDatei?: (ordner: string) => void;
    onNeuerOrdner?: (ordner: string) => void;
    onHochladen?: (ordner: string) => void;
    /** Einen ganzen Ordner in diesen hochladen, Unterordner bleiben erhalten (0.6.2). */
    onOrdnerHochladen?: (ordner: string) => void;
    onUmbenennen?: (pfad: string) => void;
    onLoeschen?: (pfad: string, typ: 'file' | 'folder') => void;
    onHerunterladen?: (pfad: string) => void;
    nurLesen?: boolean;
    leerText?: string;
    /** Adresse eines Vorschaubildes, oder null fuer „Symbol". Fehlt es, bleibt es bei Symbolen. */
    vorschauUrl?: (pfad: string) => string | null;
    /**
     * Ein Ordner ist aufgegangen, dessen Inhalt noch nicht geladen ist
     * (`children` fehlt). Fuer externe Quellen, die ebenenweise laden (0.6.0).
     */
    onOrdnerOeffnen?: (pfad: string) => void;
    /** Etwas rechts am Eintrag, vor dem ⋮ (0.6.1) — z. B. wer die Datei gerade bearbeitet. */
    renderBaumMarke?: (slot: { pfad: string; art: 'datei' | 'ordner' }) => ReactNode;
}

const PRAEFIX = 'peppermint-vault-offen:';

function gemerkt(schluessel: string): Set<string> {
    try {
        const roh = globalThis.localStorage?.getItem(PRAEFIX + schluessel);
        return roh ? new Set(JSON.parse(roh) as string[]) : new Set();
    } catch {
        return new Set();
    }
}

function merken(schluessel: string, pfade: Set<string>): void {
    try {
        globalThis.localStorage?.setItem(PRAEFIX + schluessel, JSON.stringify([...pfade]));
    } catch {
        // Privates Fenster oder voller Speicher: Der Baum geht trotzdem, er vergisst nur.
    }
}

type EintragProps = Omit<OrdnerbaumProps, 'baum' | 'leerText'> & {
    knoten: Baumeintrag;
    tiefe: number;
};

function Eintrag(props: EintragProps) {
    const { knoten, tiefe, aktuellerPfad, speicherSchluessel, onWaehlen, onLesen, nurLesen } = props;
    const istOrdner = knoten.type === 'folder';
    const liegtAufPfad = istOrdner && aktuellerPfad !== '' && (aktuellerPfad === knoten.path || aktuellerPfad.startsWith(knoten.path + '/'));

    const [offen, setOffen] = useState(() => istOrdner && (gemerkt(speicherSchluessel).has(knoten.path) || liegtAufPfad));

    // Beim Wechsel des aktuellen Pfads den Ordner darauf aufklappen — beim
    // Rendern, nicht im Effekt (sonst ein Durchlauf mit zugeklapptem Ordner).
    const [vorigerPfad, setVorigerPfad] = useState(aktuellerPfad);
    if (vorigerPfad !== aktuellerPfad) {
        setVorigerPfad(aktuellerPfad);
        if (liegtAufPfad && !offen) {
            setOffen(true);
        }
    }

    useEffect(() => {
        if (!istOrdner) {
            return;
        }
        const pfade = gemerkt(speicherSchluessel);
        if (offen) {
            pfade.add(knoten.path);
        } else {
            pfade.delete(knoten.path);
        }
        merken(speicherSchluessel, pfade);
    }, [offen, istOrdner, knoten.path, speicherSchluessel]);

    // Ebenenweise geladene Quellen: Inhalt erst holen, wenn der Ordner aufgeht.
    const { onOrdnerOeffnen } = props;
    useEffect(() => {
        if (istOrdner && offen && knoten.children === undefined) {
            onOrdnerOeffnen?.(knoten.path);
        }
    }, [istOrdner, offen, knoten.children, knoten.path, onOrdnerOeffnen]);

    const klick = useCallback(() => {
        if (istOrdner) {
            setOffen((vorher) => !vorher);
        } else {
            onWaehlen(knoten.path);
        }
    }, [istOrdner, knoten.path, onWaehlen]);

    const hatMenue = !nurLesen || (!istOrdner && props.onHerunterladen);

    return (
        <div>
            <div
                className={cn(
                    'group flex w-full items-center rounded-md pr-1 hover:bg-accent hover:text-accent-foreground',
                    knoten.path === aktuellerPfad && 'bg-accent font-medium text-accent-foreground',
                )}
            >
                <button
                    type="button"
                    onClick={klick}
                    aria-expanded={istOrdner ? offen : undefined}
                    className="flex min-w-0 flex-1 items-center gap-1 px-2 py-1 text-left text-sm"
                    style={{ paddingLeft: `${tiefe * 12 + 8}px` }}
                >
                    {istOrdner ? (
                        <>
                            {offen ? <ChevronDown className="size-3.5 shrink-0 text-muted-foreground" /> : <ChevronRight className="size-3.5 shrink-0 text-muted-foreground" />}
                            {offen ? <FolderOpen className="size-4 shrink-0 text-primary" /> : <Folder className="size-4 shrink-0 text-primary" />}
                        </>
                    ) : props.vorschauUrl?.(knoten.path) ? (
                        <>
                            <span className="w-3.5" />
                            {/* lazy: Auf einer Bildablage stehen hunderte Zeilen untereinander. */}
                            <img
                                src={props.vorschauUrl(knoten.path) ?? undefined}
                                alt=""
                                loading="lazy"
                                decoding="async"
                                className="size-6 shrink-0 rounded-sm border object-cover"
                                onError={(e) => {
                                    // Kein zerbrochenes Bild stehen lassen — das sieht nach Datenverlust aus.
                                    e.currentTarget.style.display = 'none';
                                }}
                            />
                        </>
                    ) : (
                        <>
                            <span className="w-3.5" />
                            <FileText className="size-4 shrink-0 text-muted-foreground" />
                        </>
                    )}
                    <span className="truncate">{knoten.name}</span>
                </button>

                {!istOrdner && onLesen && (
                    <button
                        type="button"
                        onClick={() => onLesen(knoten.path)}
                        className="shrink-0 rounded p-1 text-muted-foreground hover:bg-background hover:text-foreground md:opacity-0 md:group-hover:opacity-100 md:focus-visible:opacity-100"
                        title="Bildschirmfüllend lesen"
                        aria-label={`${knoten.name} bildschirmfüllend lesen`}
                    >
                        <Eye className="size-3.5" />
                    </button>
                )}

                {props.renderBaumMarke && (
                    <span className="flex shrink-0 items-center">
                        {props.renderBaumMarke({
                            pfad: knoten.path,
                            art: istOrdner ? 'ordner' : 'datei',
                        })}
                    </span>
                )}
                {hatMenue && (
                    <DropdownMenu>
                        <DropdownMenuTrigger
                            className="shrink-0 rounded p-1 text-muted-foreground hover:bg-background hover:text-foreground md:opacity-0 md:group-hover:opacity-100 md:focus-visible:opacity-100 data-[state=open]:opacity-100"
                            aria-label={`Aktionen für ${knoten.name}`}
                        >
                            <MoreVertical className="size-3.5" />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            {istOrdner && !nurLesen && (
                                <>
                                    <DropdownMenuItem onSelect={() => props.onNeueDatei?.(knoten.path)}>
                                        <FilePlus /> Neue Datei
                                    </DropdownMenuItem>
                                    <DropdownMenuItem onSelect={() => props.onNeuerOrdner?.(knoten.path)}>
                                        <FolderPlus /> Neuer Ordner
                                    </DropdownMenuItem>
                                    <DropdownMenuItem onSelect={() => props.onHochladen?.(knoten.path)}>
                                        <Upload /> Datei hochladen
                                    </DropdownMenuItem>
                                    {props.onOrdnerHochladen && (
                                        <DropdownMenuItem onSelect={() => props.onOrdnerHochladen?.(knoten.path)}>
                                            <FolderUp /> Ordner hochladen
                                        </DropdownMenuItem>
                                    )}
                                    <DropdownMenuSeparator />
                                </>
                            )}
                            {!istOrdner && props.onHerunterladen && (
                                <DropdownMenuItem onSelect={() => props.onHerunterladen?.(knoten.path)}>
                                    <Download /> Herunterladen
                                </DropdownMenuItem>
                            )}
                            {!nurLesen && (
                                <>
                                    <DropdownMenuItem onSelect={() => props.onUmbenennen?.(knoten.path)}>
                                        <Pencil /> Umbenennen
                                    </DropdownMenuItem>
                                    <DropdownMenuItem variant="destructive" onSelect={() => props.onLoeschen?.(knoten.path, knoten.type)}>
                                        <Trash2 /> Löschen
                                    </DropdownMenuItem>
                                </>
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </div>

            {istOrdner && offen && knoten.children && (
                <div role="group">
                    {knoten.children.map((kind) => (
                        <Eintrag key={kind.path} {...props} knoten={kind} tiefe={tiefe + 1} />
                    ))}
                </div>
            )}
        </div>
    );
}

export function Ordnerbaum({ baum, leerText = 'Hier liegt noch nichts.', ...rest }: OrdnerbaumProps) {
    if (baum.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center py-8 text-sm text-muted-foreground">
                <Folder className="mb-2 size-8 opacity-50" />
                <p>{leerText}</p>
            </div>
        );
    }

    return (
        <div className="py-1" role="tree">
            {baum.map((knoten) => (
                <Eintrag key={knoten.path} {...rest} knoten={knoten} tiefe={0} />
            ))}
        </div>
    );
}

export default Ordnerbaum;
