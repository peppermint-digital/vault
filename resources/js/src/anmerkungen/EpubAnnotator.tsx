import { PanelRightClose, PanelRightOpen } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { VaultApi } from '../transport';
import AnnotationList, { type AnnotationListItem } from './AnmerkungsListe';
import AnnotationToolbar from './AnmerkungsLeiste';
import { type AnnotationColor, colorHex, normalizeSelection, type EpubAnnotation } from './farben';

/**
 * EPUB lesen und anmerken (AI Brain `components/notes/epub-annotator.tsx`).
 * Gelesen wird die HTML-Lesefassung `<buch>.html` neben dem EPUB; angemerkt wird
 * das EPUB (Datei `<buch>.epub.annot.json` daneben, Server-Seite im Paket).
 */
export interface EpubAnnotatorProps {
    api: VaultApi;
    scope: string;
    path: string;
    /** Adresse der Lesefassung, z.B. api.rohUrl(scope, pfad.replace(/\.epub$/i, '.html')). */
    htmlUrl: string;
}

interface Selection {
    quote: string;
    chapter: string | null;
    x: number;
    y: number;
}

const MARK_ATTR = 'data-ai-annot';

export function EpubAnnotator({ api, scope, path, htmlUrl }: EpubAnnotatorProps) {
    const iframeRef = useRef<HTMLIFrameElement>(null);
    const [annotations, setAnnotations] = useState<EpubAnnotation[]>([]);
    const [selection, setSelection] = useState<Selection | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [showList, setShowList] = useState(true);
    const [docReady, setDocReady] = useState(false);

    useEffect(() => {
        api.anmerkungen(scope, path)
            .then((r) => setAnnotations(r.annotations as unknown as EpubAnnotation[]))
            .catch(() => {
                /* listing failure shouldn't block reading */
            });
    }, [api, scope, path]);

    /** Nearest preceding heading = chapter/section context for a node. */
    const chapterFor = (node: Node | null): string | null => {
        let el =
            node?.nodeType === Node.TEXT_NODE
                ? node.parentElement
                : (node as HTMLElement | null);
        while (el) {
            let sib: Element | null = el;
            while (sib) {
                if (/^H[1-6]$/.test(sib.tagName)) {
                    return sib.textContent?.trim() || null;
                }
                sib = sib.previousElementSibling;
            }
            el = el.parentElement;
        }
        return null;
    };

    const handleSelection = useCallback(() => {
        const iframe = iframeRef.current;
        const win = iframe?.contentWindow;
        const sel = win?.getSelection();
        if (!iframe || !sel || sel.isCollapsed) {
            return;
        }
        const quote = normalizeSelection(sel.toString());
        if (!quote) {
            return;
        }
        const range = sel.getRangeAt(0);
        const rect = range.getBoundingClientRect();
        setSelection({
            quote,
            chapter: chapterFor(range.startContainer),
            x: rect.left + rect.width / 2,
            y: rect.top,
        });
    }, []);

    /** Wrap each annotation's quote (when it sits in a single text node). */
    const renderHighlights = useCallback(() => {
        const docu = iframeRef.current?.contentDocument;
        if (!docu?.body) return;

        // Clear previous injected marks first (unwrap → normalize).
        docu.querySelectorAll(`mark[${MARK_ATTR}]`).forEach((m) => {
            const parent = m.parentNode;
            if (!parent) return;
            while (m.firstChild) parent.insertBefore(m.firstChild, m);
            parent.removeChild(m);
            parent.normalize();
        });

        for (const a of annotations) {
            const needle = a.quote;
            if (!needle) continue;
            const walker = docu.createTreeWalker(
                docu.body,
                NodeFilter.SHOW_TEXT,
            );
            let textNode: Text | null = null;
            let offset = -1;
            while (walker.nextNode()) {
                const node = walker.currentNode as Text;
                const idx = node.textContent?.indexOf(needle) ?? -1;
                if (idx >= 0) {
                    textNode = node;
                    offset = idx;
                    break;
                }
            }
            if (!textNode || offset < 0) continue;

            const range = docu.createRange();
            range.setStart(textNode, offset);
            range.setEnd(textNode, offset + needle.length);
            const mark = docu.createElement('mark');
            mark.setAttribute(MARK_ATTR, a.id);
            mark.id = `annot-${a.id}`;
            // Solid colour + multiply = a real highlighter (text stays readable,
            // mark clearly visible) instead of a near-white low-alpha wash.
            mark.style.backgroundColor = colorHex(a.color);
            mark.style.mixBlendMode = 'multiply';
            mark.style.color = 'inherit';
            if (a.note) mark.title = a.note;
            try {
                range.surroundContents(mark);
            } catch {
                /* quote spans element boundaries — leave it to the sidebar */
            }
        }
    }, [annotations]);

    // Attach listeners inside the same-origin companion document on load.
    const onIframeLoad = useCallback(() => {
        const docu = iframeRef.current?.contentDocument;
        if (!docu) return;
        docu.addEventListener('mouseup', handleSelection);
        docu.addEventListener('scroll', () => setSelection(null), true);
        setDocReady(true);
    }, [handleSelection]);

    // (Re)paint highlights whenever the set changes and the doc is ready.
    useEffect(() => {
        if (docReady) renderHighlights();
    }, [docReady, renderHighlights]);

    const clearSelection = useCallback(() => {
        iframeRef.current?.contentWindow?.getSelection()?.removeAllRanges();
        setSelection(null);
    }, []);

    const submit = useCallback(
        async (color: AnnotationColor, note?: string) => {
            if (!selection) return;
            setBusy(true);
            setError(null);
            try {
                const r = await api.anmerkungAnlegen(scope, {
                    path,
                    quote: selection.quote,
                    color,
                    ...(selection.chapter
                        ? { chapter: selection.chapter }
                        : {}),
                    ...(note ? { note } : {}),
                });
                setAnnotations(r.annotations as unknown as EpubAnnotation[]);
                clearSelection();
            } catch (e) {
                setError(
                    e instanceof Error
                        ? e.message
                        : 'Markieren fehlgeschlagen.',
                );
            } finally {
                setBusy(false);
            }
        },
        [api, scope, selection, path, clearSelection],
    );

    const handleDelete = useCallback(
        async (id: string) => {
            setBusy(true);
            setError(null);
            try {
                const r = await api.anmerkungEntfernen(scope, { path, id });
                setAnnotations(r.annotations as unknown as EpubAnnotation[]);
            } catch (e) {
                setError(
                    e instanceof Error
                        ? e.message
                        : 'Entfernen fehlgeschlagen.',
                );
            } finally {
                setBusy(false);
            }
        },
        [api, scope, path],
    );

    const handleUpdateNote = useCallback(
        async (id: string, note: string) => {
            setBusy(true);
            setError(null);
            try {
                const r = await api.anmerkungAendern(scope, {
                    path,
                    id,
                    note,
                });
                setAnnotations(r.annotations as unknown as EpubAnnotation[]);
            } catch (e) {
                setError(
                    e instanceof Error
                        ? e.message
                        : 'Notiz speichern fehlgeschlagen.',
                );
            } finally {
                setBusy(false);
            }
        },
        [api, scope, path],
    );

    const jumpTo = (id: string) => {
        iframeRef.current?.contentDocument
            ?.getElementById(`annot-${id}`)
            ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    };

    const listItems: AnnotationListItem[] = annotations.map((a) => ({
        key: a.id,
        color: a.color,
        quote: a.quote,
        note: a.note,
        context: a.chapter,
        onJump: () => jumpTo(a.id),
        onDelete: () => handleDelete(a.id),
        onSaveNote: (note: string) => handleUpdateNote(a.id, note),
    }));

    return (
        <div className="flex min-h-0 flex-1">
            <div className="relative min-h-0 flex-1">
                <iframe
                    ref={iframeRef}
                    title={path}
                    onLoad={onIframeLoad}
                    /* Bug #951: Hier faellt `allow-scripts` weg statt
                     `allow-same-origin` — genau andersherum als in der Notiz-Ansicht.
                     Der Annotator liest `contentDocument`, `contentWindow` und
                     `getSelection()` des Rahmens; ohne same-origin waere das Markieren
                     und Kommentieren tot. Skripte braucht der Inhalt dagegen nicht:
                     Das erzeugte EPUB-HTML enthaelt keine (nachgezaehlt am Bestand).
                     Damit laeuft dort gar nichts, was die Sitzung lesen koennte. */
                    sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                    src={htmlUrl}
                    className="h-full w-full border-0 bg-background"
                />
                {selection && (
                    <AnnotationToolbar
                        x={selection.x}
                        y={selection.y}
                        busy={busy}
                        onHighlight={(color) => submit(color)}
                        onComment={(note, color) => submit(color, note)}
                        onClose={clearSelection}
                    />
                )}
            </div>

            <button
                type="button"
                onClick={() => setShowList((v) => !v)}
                title={showList ? 'Liste ausblenden' : 'Markierungen anzeigen'}
                className="flex shrink-0 items-center border-l px-1 text-muted-foreground hover:bg-muted hover:text-foreground"
            >
                {showList ? (
                    <PanelRightClose className="h-4 w-4" />
                ) : (
                    <PanelRightOpen className="h-4 w-4" />
                )}
            </button>

            {showList && (
                <aside className="flex w-64 shrink-0 flex-col overflow-auto border-l bg-background">
                    <div className="border-b px-3 py-2 text-xs font-semibold text-muted-foreground">
                        Markierungen ({annotations.length})
                    </div>
                    {error && (
                        <div className="border-b bg-destructive/10 px-3 py-2 text-xs text-destructive">
                            {error}
                        </div>
                    )}
                    <AnnotationList items={listItems} busy={busy} />
                </aside>
            )}
        </div>
    );
}

export default EpubAnnotator;
