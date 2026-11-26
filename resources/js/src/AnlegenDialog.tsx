import { useEffect, useState, type FormEvent } from 'react';
import { Button } from './ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from './ui/dialog';
import { Input } from './ui/input';
import { Label } from './ui/label';

/**
 * Ein Name fuer etwas Neues — oder ein neuer Name fuer etwas Vorhandenes.
 *
 * Der Dialog schliesst sich erst, wenn `onBestaetigen` gelungen ist. Wirft es,
 * bleibt er offen und zeigt die Meldung: In AI Brain lief eine Absage beim
 * Anlegen eine Zeit lang ins Leere, und im Dialog passierte sichtbar NICHTS —
 * nicht zu unterscheiden von „gespeichert" (#6293).
 */
export interface AnlegenDialogProps {
    offen: boolean;
    art: 'datei' | 'ordner' | 'umbenennen';
    /** Ordner, in dem angelegt wird (relativ), oder der alte Pfad beim Umbenennen. */
    ausgang: string;
    onSchliessen: () => void;
    /** Bekommt den vollen neuen Pfad. */
    onBestaetigen: (pfad: string) => Promise<void>;
}

const TITEL = { datei: 'Neue Datei', ordner: 'Neuer Ordner', umbenennen: 'Umbenennen' } as const;

function ordnerVon(pfad: string): string {
    const i = pfad.lastIndexOf('/');
    return i === -1 ? '' : pfad.slice(0, i);
}

export function AnlegenDialog({ offen, art, ausgang, onSchliessen, onBestaetigen }: AnlegenDialogProps) {
    const startname = art === 'umbenennen' ? ausgang.split('/').pop() ?? '' : art === 'datei' ? 'Neue Notiz.md' : 'Neuer Ordner';
    const [name, setName] = useState(startname);
    const [fehler, setFehler] = useState<string | null>(null);
    const [laeuft, setLaeuft] = useState(false);

    useEffect(() => {
        if (offen) {
            setName(startname);
            setFehler(null);
        }
    }, [offen, startname]);

    async function absenden(e: FormEvent) {
        e.preventDefault();
        const sauber = name.trim();

        if (sauber === '' || sauber.includes('/') || sauber === '.' || sauber === '..') {
            setFehler('Bitte einen Namen ohne Schrägstrich angeben.');
            return;
        }

        const ordner = art === 'umbenennen' ? ordnerVon(ausgang) : ausgang;
        let pfad = ordner === '' ? sauber : `${ordner}/${sauber}`;
        if (art === 'datei' && !/\.[a-z0-9]+$/i.test(sauber)) {
            pfad += '.md';
        }

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

    return (
        <Dialog open={offen} onOpenChange={(wert) => !wert && onSchliessen()}>
            <DialogContent>
                <form onSubmit={absenden} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>{TITEL[art]}</DialogTitle>
                        <DialogDescription>
                            {art === 'umbenennen' ? `Bisher: ${ausgang}` : ausgang === '' ? 'Ganz oben im Bereich.' : `In: ${ausgang}`}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="vault-name">Name</Label>
                        <Input id="vault-name" value={name} autoFocus onChange={(e) => setName(e.target.value)} aria-invalid={fehler !== null} />
                        {fehler !== null && (
                            <p role="alert" className="text-sm text-destructive">
                                {fehler}
                            </p>
                        )}
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onSchliessen}>
                            Abbrechen
                        </Button>
                        <Button type="submit" disabled={laeuft}>
                            {art === 'umbenennen' ? 'Umbenennen' : 'Anlegen'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default AnlegenDialog;
