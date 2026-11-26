import { History, RotateCcw } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { VaultApi, Versionszeile } from './transport';
import { Button } from './ui/button';

/**
 * Die Staende einer Datei (AI Brain `pages/vault-versions.tsx`, als Baustein
 * statt eigener Seite). Wiederherstellen ueberschreibt nichts endgueltig:
 * Der Server legt dabei selbst eine neue Version an.
 */
export interface VersionslisteProps {
    api: VaultApi;
    scope: string;
    pfad: string;
    darfWiederherstellen: boolean;
    onWiederhergestellt?: () => void;
}

const zeit = (iso: string | null) => (iso ? new Date(iso).toLocaleString('de-DE', { dateStyle: 'medium', timeStyle: 'short' }) : '–');

export function Versionsliste({ api, scope, pfad, darfWiederherstellen, onWiederhergestellt }: VersionslisteProps) {
    const [zeilen, setZeilen] = useState<Versionszeile[] | null>(null);
    const [fehler, setFehler] = useState<string | null>(null);
    const [vorschau, setVorschau] = useState<{ id: number; inhalt: string | null } | null>(null);
    const [neu, setNeu] = useState(0);

    useEffect(() => {
        let ab = false;
        setZeilen(null);
        setFehler(null);
        api.versionen(scope, pfad)
            .then((d) => !ab && setZeilen(d.versions))
            .catch((e: Error) => !ab && setFehler(e.message));
        return () => {
            ab = true;
        };
    }, [api, scope, pfad, neu]);

    async function zeigen(id: number) {
        try {
            const d = await api.versionInhalt(id);
            setVorschau({ id, inhalt: d.content });
        } catch (e) {
            setFehler((e as Error).message);
        }
    }

    async function zurueck(id: number) {
        try {
            await api.versionWiederherstellen(id);
            setVorschau(null);
            setNeu((n) => n + 1);
            onWiederhergestellt?.();
        } catch (e) {
            setFehler((e as Error).message);
        }
    }

    if (fehler !== null) {
        return <p role="alert" className="p-3 text-sm text-destructive">{fehler}</p>;
    }

    if (zeilen === null) {
        return <p className="p-3 text-sm text-muted-foreground">Versionen werden geladen…</p>;
    }

    if (zeilen.length === 0) {
        return <p className="p-3 text-sm text-muted-foreground">Noch keine Versionen.</p>;
    }

    return (
        <div className="grid gap-2">
            <ul className="divide-y rounded-md border">
                {zeilen.map((v, i) => (
                    <li key={v.id} className="flex items-center gap-2 px-3 py-2 text-sm">
                        <History className="size-4 shrink-0 text-muted-foreground" />
                        <div className="min-w-0 flex-1">
                            <div>
                                {zeit(v.created_at)}
                                {i === 0 && <span className="ml-2 text-xs text-muted-foreground">(aktuell)</span>}
                            </div>
                            <div className="text-xs text-muted-foreground">
                                {v.author ?? 'unbekannt'} · {v.binary ? 'Datei' : `${v.size} Zeichen`}
                            </div>
                        </div>
                        {!v.binary && (
                            <Button size="sm" variant="ghost" onClick={() => zeigen(v.id)}>
                                Ansehen
                            </Button>
                        )}
                        {darfWiederherstellen && i > 0 && (
                            <Button size="sm" variant="outline" onClick={() => zurueck(v.id)}>
                                <RotateCcw /> Wiederherstellen
                            </Button>
                        )}
                    </li>
                ))}
            </ul>
            {vorschau !== null && (
                <pre className="max-h-80 overflow-auto rounded-md border bg-muted p-3 text-xs whitespace-pre-wrap" aria-label="Inhalt der Version">
                    {vorschau.inhalt}
                </pre>
            )}
        </div>
    );
}

export default Versionsliste;
