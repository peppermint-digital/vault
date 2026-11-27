import { CornerDownRight } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { VaultApi } from './transport';
import type { Rueckverweis } from './typen';

/**
 * „Verweise hierher" unter einer Notiz (0.7.0, mit aibrain-e1 abgestimmt):
 * welche Dateien des Bereichs auf diese verweisen. Klick oeffnet sie im Browser.
 * Leer = nichts zeigen. Fehler (z.B. Quelle, 404) = nichts zeigen - die Liste
 * ist eine Zugabe, sie darf die Ansicht nie stoeren.
 */
export interface RueckverweiseProps {
    api: VaultApi;
    scope: string;
    pfad: string;
    onOeffnen: (pfad: string) => void;
}

export function Rueckverweise({ api, scope, pfad, onOeffnen }: RueckverweiseProps) {
    const [liste, setListe] = useState<Rueckverweis[]>([]);

    useEffect(() => {
        let ab = false;
        api.rueckverweise(scope, pfad)
            .then((d) => !ab && setListe(d.backlinks ?? []))
            .catch(() => !ab && setListe([]));
        return () => {
            ab = true;
        };
    }, [api, scope, pfad]);

    if (liste.length === 0) {
        return null;
    }

    return (
        <section className="border-t px-4 py-3" aria-label="Verweise hierher">
            <h3 className="mb-1 text-sm font-medium text-muted-foreground">Verweise hierher</h3>
            <ul className="grid gap-0.5">
                {liste.map((v) => (
                    <li key={v.path}>
                        <button type="button" onClick={() => onOeffnen(v.path)} className="flex w-full items-center gap-1.5 rounded px-1 py-0.5 text-left text-sm hover:bg-accent">
                            <CornerDownRight className="size-3.5 shrink-0 text-muted-foreground" />
                            <span className="truncate">{v.label}</span>
                            {v.path !== `${v.label}.md` && <span className="truncate text-xs text-muted-foreground">{v.path}</span>}
                        </button>
                    </li>
                ))}
            </ul>
        </section>
    );
}

export default Rueckverweise;
