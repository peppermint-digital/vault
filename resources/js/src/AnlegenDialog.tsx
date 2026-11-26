import { FileSpreadsheet, FileText, Folder, NotebookPen, Presentation, type LucideIcon } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { Button } from './ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from './ui/dialog';
import { Input } from './ui/input';
import { Label } from './ui/label';
import { cn } from './ui/utils';

/**
 * Ein Name fuer etwas Neues — oder ein neuer Name fuer etwas Vorhandenes.
 *
 * Der Dialog schliesst sich erst, wenn `onBestaetigen` gelungen ist. Wirft es,
 * bleibt er offen und zeigt die Meldung: In AI Brain lief eine Absage beim
 * Anlegen eine Zeit lang ins Leere, und im Dialog passierte sichtbar NICHTS —
 * nicht zu unterscheiden von „gespeichert" (#6293).
 *
 * Die Art der Datei waehlt man mit einem Klick, die Endung steht neben dem
 * Namen und wird nicht mitgetippt. Office-Arten zeigt der Dialog nur, wenn das
 * Produkt sie anbietet (`formate`) — eine .docx ohne Editor laesst sich nur
 * herunterladen, und das ist kein Anlegen, sondern eine Sackgasse.
 */
export type Dateiformat = 'md' | 'docx' | 'xlsx' | 'pptx';

export interface AnlegenDialogProps {
    offen: boolean;
    art: 'datei' | 'ordner' | 'umbenennen';
    /** Ordner, in dem angelegt wird (relativ), oder der alte Pfad beim Umbenennen. */
    ausgang: string;
    onSchliessen: () => void;
    /** Bekommt den vollen neuen Pfad. */
    onBestaetigen: (pfad: string) => Promise<void>;
    /** Welche Arten eine neue Datei haben kann. Vorgabe: nur Notiz. */
    formate?: Dateiformat[];
    /** Namen, die es im Zielordner schon gibt — fuer den Hinweis vor dem Absenden. */
    vorhanden?: string[];
    /** Name des Bereichs fuer die Ortsangabe, z.B. „Mein Arbeits-Vault". */
    bereichName?: string;
}

interface Formatangabe {
    titel: string;
    vorname: string;
    symbol: LucideIcon;
}

const FORMATE: Record<Dateiformat, Formatangabe> = {
    md: { titel: 'Notiz', vorname: 'Neue Notiz', symbol: NotebookPen },
    docx: { titel: 'Dokument', vorname: 'Neues Dokument', symbol: FileText },
    xlsx: { titel: 'Tabelle', vorname: 'Neue Tabelle', symbol: FileSpreadsheet },
    pptx: { titel: 'Präsentation', vorname: 'Neue Präsentation', symbol: Presentation },
};

const TITEL = { datei: 'Neue Datei', ordner: 'Neuer Ordner', umbenennen: 'Umbenennen' } as const;

function ordnerVon(pfad: string): string {
    const i = pfad.lastIndexOf('/');
    return i === -1 ? '' : pfad.slice(0, i);
}

/** Endung ohne Punkt, oder null. Ein Punkt am Anfang („.env") ist keine Endung. */
function endungVon(name: string): string | null {
    const i = name.lastIndexOf('.');
    return i > 0 && i < name.length - 1 ? name.slice(i + 1).toLowerCase() : null;
}

function namensfehler(name: string): string | null {
    if (name === '') {
        return 'Bitte einen Namen eingeben.';
    }
    if (name.includes('/')) {
        return 'Ein Name darf keinen Schrägstrich enthalten.';
    }
    if (name === '.' || name === '..') {
        return 'Dieser Name ist nicht erlaubt.';
    }
    return null;
}

export function AnlegenDialog({ offen, art, ausgang, onSchliessen, onBestaetigen, formate = ['md'], vorhanden = [], bereichName }: AnlegenDialogProps) {
    const angeboten: Dateiformat[] = formate.length > 0 ? formate : ['md'];
    const erstesFormat = angeboten[0];
    const altName = art === 'umbenennen' ? (ausgang.split('/').pop() ?? '') : '';
    const startname = art === 'umbenennen' ? altName : art === 'datei' ? FORMATE[erstesFormat].vorname : 'Neuer Ordner';

    const [name, setName] = useState(startname);
    const [format, setFormat] = useState<Dateiformat>(erstesFormat);
    const [beruehrt, setBeruehrt] = useState(false);
    const [fehler, setFehler] = useState<string | null>(null);
    const [laeuft, setLaeuft] = useState(false);
    const feld = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (offen) {
            setName(startname);
            setFormat(erstesFormat);
            setBeruehrt(false);
            setFehler(null);
        }
    }, [offen, startname, erstesFormat]);

    // Tippt jemand „Angebot.docx", ist das die Wahl der Art — die Kachel springt mit.
    const getippteEndung = art === 'datei' ? endungVon(name.trim()) : null;
    const getipptesFormat = getippteEndung !== null && (angeboten as string[]).includes(getippteEndung) ? (getippteEndung as Dateiformat) : null;
    const wirksamesFormat = getipptesFormat ?? format;
    const eigeneEndung = getippteEndung !== null && getipptesFormat === null;
    const zusatz = art === 'datei' && getippteEndung === null ? `.${wirksamesFormat}` : '';

    const ordner = art === 'umbenennen' ? ordnerVon(ausgang) : ausgang;
    const endname = name.trim() + zusatz;
    const belegt = useMemo(() => {
        const klein = endname.toLowerCase();
        return klein !== altName.toLowerCase() && vorhanden.some((v) => v.toLowerCase() === klein);
    }, [endname, altName, vorhanden]);

    const ort = [bereichName ?? 'Ganz oben', ...ordner.split('/').filter(Boolean)].join(' › ');

    function waehle(neu: Dateiformat) {
        setFormat(neu);
        setFehler(null);
        // Den Vorschlag mitnehmen, solange niemand selbst einen Namen getippt hat.
        if (!beruehrt) {
            setName(FORMATE[neu].vorname);
        } else if (getipptesFormat !== null) {
            setName(name.trim().slice(0, name.trim().lastIndexOf('.')));
        }
    }

    function markieren() {
        const f = feld.current;
        if (f === null) {
            return;
        }
        f.focus();
        // Beim Umbenennen nur den Namen ohne Endung markieren, wie im Dateimanager.
        const punkt = art === 'umbenennen' ? f.value.lastIndexOf('.') : -1;
        f.setSelectionRange(0, punkt > 0 ? punkt : f.value.length);
    }

    async function absenden(e: FormEvent) {
        e.preventDefault();
        const sauber = name.trim();
        const problem = namensfehler(sauber);

        if (problem !== null) {
            setFehler(problem);
            return;
        }
        if (belegt) {
            setFehler('Unter diesem Namen gibt es hier schon etwas.');
            return;
        }

        const ziel = sauber + zusatz;
        const pfad = ordner === '' ? ziel : `${ordner}/${ziel}`;

        setLaeuft(true);
        setFehler(null);
        try {
            await onBestaetigen(pfad);
            onSchliessen();
        } catch (fehlerObjekt) {
            setFehler(fehlerObjekt instanceof Error ? fehlerObjekt.message : 'Das ging nicht.');
        } finally {
            setLaeuft(false);
        }
    }

    const knopf = art === 'umbenennen' ? 'Umbenennen' : art === 'ordner' ? 'Ordner anlegen' : eigeneEndung ? 'Datei anlegen' : `${FORMATE[wirksamesFormat].titel} anlegen`;
    const meldung = fehler ?? (belegt ? 'Unter diesem Namen gibt es hier schon etwas.' : null);

    return (
        <Dialog open={offen} onOpenChange={(wert) => !wert && onSchliessen()}>
            <DialogContent
                className="sm:max-w-md"
                onOpenAutoFocus={(e) => {
                    e.preventDefault();
                    markieren();
                }}
            >
                <form onSubmit={absenden} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{TITEL[art]}</DialogTitle>
                        <DialogDescription className="flex items-center gap-1.5">
                            <Folder className="size-3.5 shrink-0" aria-hidden />
                            <span className="truncate">{art === 'umbenennen' ? `Bisher: ${altName}` : ort}</span>
                        </DialogDescription>
                    </DialogHeader>

                    {art === 'datei' && angeboten.length > 1 && (
                        <fieldset className="grid gap-2">
                            <legend className="mb-2 text-sm font-medium">Art</legend>
                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                {angeboten.map((f) => {
                                    const { titel, symbol: Symbol } = FORMATE[f];
                                    const gewaehlt = wirksamesFormat === f && !eigeneEndung;
                                    return (
                                        <label
                                            key={f}
                                            className={cn(
                                                'flex cursor-pointer flex-col items-center gap-1 rounded-md border px-2 py-3 text-center text-sm transition-colors',
                                                'has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50',
                                                gewaehlt ? 'border-primary bg-accent text-accent-foreground' : 'border-input hover:bg-accent/50',
                                            )}
                                        >
                                            <input type="radio" name="vault-format" value={f} checked={gewaehlt} onChange={() => waehle(f)} className="sr-only" />
                                            <Symbol className="size-5" aria-hidden />
                                            <span className="font-medium">{titel}</span>
                                            <span className="text-xs text-muted-foreground">.{f}</span>
                                        </label>
                                    );
                                })}
                            </div>
                        </fieldset>
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="vault-name">Name</Label>
                        <div className="flex items-stretch">
                            <Input
                                id="vault-name"
                                ref={feld}
                                value={name}
                                autoComplete="off"
                                spellCheck={false}
                                className={cn(zusatz !== '' && 'rounded-r-none')}
                                onChange={(e) => {
                                    setName(e.target.value);
                                    setBeruehrt(true);
                                    setFehler(null);
                                }}
                                aria-invalid={meldung !== null}
                                aria-describedby="vault-name-hinweis"
                            />
                            {zusatz !== '' && (
                                <span className="flex items-center rounded-r-md border border-l-0 border-input bg-muted px-3 text-sm text-muted-foreground" aria-hidden>
                                    {zusatz}
                                </span>
                            )}
                        </div>
                        <p
                            id="vault-name-hinweis"
                            role={meldung !== null ? 'alert' : undefined}
                            className={cn('min-h-5 text-sm', meldung !== null ? 'text-destructive' : 'text-muted-foreground')}
                        >
                            {meldung ?? (eigeneEndung ? `Wird mit der Endung .${getippteEndung} angelegt.` : '')}
                        </p>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onSchliessen}>
                            Abbrechen
                        </Button>
                        <Button type="submit" disabled={laeuft || belegt}>
                            {laeuft ? 'Wird angelegt …' : knopf}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default AnlegenDialog;
