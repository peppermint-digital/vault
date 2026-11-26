import { FileText, Folder, RotateCcw, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { Papierkorbzeile, VaultApi } from './transport';
import { Button } from './ui/button';

/**
 * Der Papierkorb eines Bereichs. Endgueltig entfernen fragt nach — es ist der
 * einzige Weg im Vault, auf dem etwas wirklich verschwindet.
 */
export interface PapierkorblisteProps {
    api: VaultApi;
    scope: string;
    darfAendern: boolean;
    onWiederhergestellt?: (pfad: string) => void;
    /** Vorgabe: window.confirm. Fuer Tests und eigene Dialoge. */
    bestaetigen?: (frage: string) => boolean;
}

export function Papierkorbliste({ api, scope, darfAendern, onWiederhergestellt, bestaetigen }: PapierkorblisteProps) {
    const [zeilen, setZeilen] = useState<Papierkorbzeile[] | null>(null);
    const [fehler, setFehler] = useState<string | null>(null);
    const [neu, setNeu] = useState(0);

    useEffect(() => {
        let ab = false;
        api.papierkorb(scope)
            .then((d) => !ab && setZeilen(d.eintraege))
            .catch((e: Error) => !ab && setFehler(e.message));
        return () => {
            ab = true;
        };
    }, [api, scope, neu]);

    async function zurueck(z: Papierkorbzeile) {
        try {
            const d = await api.papierkorbWiederherstellen(z.id);
            setNeu((n) => n + 1);
            onWiederhergestellt?.(d.path);
        } catch (e) {
            setFehler((e as Error).message);
        }
    }

    async function entfernen(z: Papierkorbzeile) {
        const frage = `„${z.original_path}" endgültig löschen? Das lässt sich nicht rückgängig machen.`;
        if (!(bestaetigen ?? ((f: string) => globalThis.confirm(f)))(frage)) {
            return;
        }
        try {
            await api.papierkorbEntfernen(z.id);
            setNeu((n) => n + 1);
        } catch (e) {
            setFehler((e as Error).message);
        }
    }

    if (fehler !== null) {
        return <p role="alert" className="p-3 text-sm text-destructive">{fehler}</p>;
    }
    if (zeilen === null) {
        return <p className="p-3 text-sm text-muted-foreground">Papierkorb wird geladen…</p>;
    }
    if (zeilen.length === 0) {
        return <p className="p-3 text-sm text-muted-foreground">Der Papierkorb ist leer.</p>;
    }

    return (
        <ul className="divide-y rounded-md border">
            {zeilen.map((z) => (
                <li key={z.id} className="flex items-center gap-2 px-3 py-2 text-sm">
                    {z.type === 'folder' ? <Folder className="size-4 shrink-0 text-muted-foreground" /> : <FileText className="size-4 shrink-0 text-muted-foreground" />}
                    <div className="min-w-0 flex-1">
                        <div className="truncate">{z.original_path}</div>
                        <div className="text-xs text-muted-foreground">
                            {z.deleted_at ? new Date(z.deleted_at).toLocaleString('de-DE', { dateStyle: 'medium', timeStyle: 'short' }) : ''}
                            {z.deleted_by ? ` · ${z.deleted_by.name}` : ''}
                        </div>
                    </div>
                    {darfAendern && (
                        <>
                            <Button size="sm" variant="outline" onClick={() => zurueck(z)}>
                                <RotateCcw /> Zurückholen
                            </Button>
                            <Button size="sm" variant="ghost" onClick={() => entfernen(z)} aria-label={`${z.original_path} endgültig löschen`}>
                                <Trash2 />
                            </Button>
                        </>
                    )}
                </li>
            ))}
        </ul>
    );
}

export default Papierkorbliste;
