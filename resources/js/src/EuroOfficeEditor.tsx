import { useEffect, useState, type ReactNode } from 'react';
import type { VaultApi } from './transport';

/**
 * Bindet den EuroOffice Document Server ein (AI Brain
 * `components/notes/eurooffice-editor.tsx`, #6217).
 *
 * Die Konfiguration kommt SIGNIERT vom eigenen Server — im Browser wird sie
 * nur durchgereicht, nie zusammengebaut. `api.js` wird je Adresse genau
 * einmal geladen.
 */
export interface EuroOfficeEditorProps {
    api: VaultApi;
    scope: string;
    pfad: string;
    /** Wird gezeigt, wenn der Editor nicht bereitsteht (z.B. ein Herunterladen-Knopf). */
    ersatz?: ReactNode;
}

type EditorKonfiguration = Record<string, unknown>;

declare global {
    interface Window {
        DocsAPI?: { DocEditor: new (id: string, config: EditorKonfiguration) => { destroyEditor?: () => void } };
    }
}

function skriptLaden(url: string): Promise<void> {
    if (window.DocsAPI) {
        return Promise.resolve();
    }

    return new Promise((fertig, fehler) => {
        const vorhanden = document.querySelector<HTMLScriptElement>(`script[data-eurooffice="${CSS.escape(url)}"]`);
        const skript = vorhanden ?? document.createElement('script');
        skript.addEventListener('load', () => fertig());
        skript.addEventListener('error', () => fehler(new Error('Der Editor-Dienst ist nicht erreichbar (api.js ließ sich nicht laden).')));

        if (!vorhanden) {
            skript.src = url;
            skript.async = true;
            skript.dataset.eurooffice = url;
            document.head.appendChild(skript);
        }
    });
}

export function EuroOfficeEditor({ api, scope, pfad, ersatz }: EuroOfficeEditorProps) {
    const [fehler, setFehler] = useState<string | null>(null);
    const [laedt, setLaedt] = useState(true);
    // Eindeutig je Einbindung: Zwei Editoren duerfen sich keinen Container teilen.
    const [behaelter] = useState(() => `vault-eurooffice-${Math.random().toString(36).slice(2, 10)}`);

    useEffect(() => {
        let ab = false;
        let editor: { destroyEditor?: () => void } | null = null;
        setFehler(null);
        setLaedt(true);

        api.euroOfficeKonfiguration(scope, pfad)
            .then(async (antwort) => {
                await skriptLaden(antwort.apiJsUrl);
                if (ab || !window.DocsAPI) {
                    return;
                }
                editor = new window.DocsAPI.DocEditor(behaelter, antwort.config);
                setLaedt(false);
            })
            .catch((e: unknown) => {
                if (!ab) {
                    setFehler(e instanceof Error ? e.message : 'Unbekannter Fehler.');
                    setLaedt(false);
                }
            });

        return () => {
            ab = true;
            // Ohne Aufraeumen bleibt beim Dateiwechsel ein toter Rahmen stehen,
            // und der Server haelt die Sitzung offen.
            try {
                editor?.destroyEditor?.();
            } catch {
                // Der Editor war nie fertig.
            }
        };
    }, [api, scope, pfad, behaelter]);

    if (fehler !== null) {
        return (
            <div className="flex flex-col items-center justify-center gap-3 p-6 text-center">
                <p role="alert" className="max-w-md text-sm text-destructive">{fehler}</p>
                {ersatz}
            </div>
        );
    }

    return (
        <div className="relative h-[75vh] w-full">
            {laedt && <div className="absolute inset-0 flex items-center justify-center bg-background text-sm text-muted-foreground">Editor wird geladen…</div>}
            <div id={behaelter} className="h-full w-full" />
        </div>
    );
}

export default EuroOfficeEditor;
