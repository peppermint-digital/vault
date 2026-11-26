import { Check, Copy, Lock, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import type { Freigabe, VaultApi } from './transport';
import { Button } from './ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from './ui/dialog';
import { Input } from './ui/input';
import { Label } from './ui/label';

/**
 * Freigabelinks einer Datei (AI Brain `components/notes/share-dialog.tsx`,
 * verkuerzt): anlegen mit optionalem Passwort und Ablauf, kopieren,
 * zurueckziehen.
 */
export interface FreigabeDialogProps {
    api: VaultApi;
    scope: string;
    pfad: string;
    offen: boolean;
    onSchliessen: () => void;
}

export function FreigabeDialog({ api, scope, pfad, offen, onSchliessen }: FreigabeDialogProps) {
    const [links, setLinks] = useState<Freigabe[]>([]);
    const [passwort, setPasswort] = useState('');
    const [tage, setTage] = useState('');
    const [fehler, setFehler] = useState<string | null>(null);
    const [kopiert, setKopiert] = useState<number | null>(null);

    const laden = useCallback(() => {
        api.freigaben(scope, pfad)
            .then((d) => setLinks(d.shares))
            .catch((e: Error) => setFehler(e.message));
    }, [api, scope, pfad]);

    useEffect(() => {
        if (offen) {
            setFehler(null);
            laden();
        }
    }, [offen, laden]);

    async function anlegen() {
        setFehler(null);
        try {
            await api.freigabeAnlegen(scope, pfad, passwort || undefined, tage ? Number(tage) : undefined);
            setPasswort('');
            setTage('');
            laden();
        } catch (e) {
            setFehler((e as Error).message);
        }
    }

    async function zurueckziehen(id: number) {
        try {
            await api.freigabeZurueckziehen(id);
            laden();
        } catch (e) {
            setFehler((e as Error).message);
        }
    }

    async function kopieren(link: Freigabe) {
        try {
            await globalThis.navigator.clipboard.writeText(link.url);
            setKopiert(link.id);
        } catch {
            setFehler('Kopieren ging nicht — den Link bitte von Hand markieren.');
        }
    }

    return (
        <Dialog open={offen} onOpenChange={(wert) => !wert && onSchliessen()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Freigeben</DialogTitle>
                    <DialogDescription>Wer den Link hat, kann „{pfad.split('/').pop()}" ohne Anmeldung lesen.</DialogDescription>
                </DialogHeader>

                {links.length > 0 && (
                    <ul className="grid gap-2">
                        {links.map((l) => (
                            <li key={l.id} className="flex items-center gap-2 rounded-md border p-2 text-sm">
                                {l.requires_password && <Lock className="size-4 shrink-0 text-muted-foreground" aria-label="mit Passwort" />}
                                <Input readOnly value={l.url} className="h-8 flex-1 text-xs" aria-label="Freigabelink" />
                                <Button size="sm" variant="ghost" onClick={() => kopieren(l)} aria-label="Link kopieren">
                                    {kopiert === l.id ? <Check /> : <Copy />}
                                </Button>
                                <Button size="sm" variant="ghost" onClick={() => zurueckziehen(l.id)} aria-label="Link zurückziehen">
                                    <Trash2 />
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}

                <div className="grid gap-3 border-t pt-3">
                    <div className="grid gap-1.5">
                        <Label htmlFor="vault-freigabe-passwort">Passwort (optional)</Label>
                        <Input id="vault-freigabe-passwort" type="password" value={passwort} onChange={(e) => setPasswort(e.target.value)} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="vault-freigabe-tage">Gültig für Tage (optional)</Label>
                        <Input id="vault-freigabe-tage" type="number" min={1} value={tage} onChange={(e) => setTage(e.target.value)} />
                    </div>
                    {fehler !== null && <p role="alert" className="text-sm text-destructive">{fehler}</p>}
                    <Button onClick={anlegen}>Neuen Link anlegen</Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

export default FreigabeDialog;
