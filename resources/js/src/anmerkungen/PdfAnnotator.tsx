import { PanelRightClose, PanelRightOpen } from 'lucide-react';
import {
    GlobalWorkerOptions,
    getDocument,
    TextLayer,
    type PDFDocumentProxy,
    type PDFPageProxy,
} from 'pdfjs-dist';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { VaultApi } from '../transport';
import AnnotationList, { type AnnotationListItem } from './AnmerkungsListe';
import AnnotationToolbar from './AnmerkungsLeiste';
import { type AnnotationColor, colorHex, normalizeSelection, type PdfAnnotation } from './farben';


/**
 * PDF lesen und anmerken (AI Brain `components/notes/pdf-annotator.tsx`).
 *
 * Das Produkt bindet ein:
 * - `pdfjs-dist` (peerDependency) und dessen Worker als Adresse:
 *   `import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url'` (Vite);
 * - die Stile `pdfjs-dist/web/pdf_viewer.css` und `@peppermint-digital/vault/annotator.css`.
 */
export interface PdfAnnotatorProps {
    api: VaultApi;
    scope: string;
    path: string;
    /** Adresse des pdf.js-Workers (im Paket nicht baubar: Vite-`?url`). */
    workerUrl: string;
}

interface Selection {
    page: number;
    text: string;
    x: number;
    y: number;
}

/** PyMuPDF marks the underlying text; these annotation types carry a quote. */
const TEXT_MARKUP = new Set([
    'Highlight',
    'Underline',
    'StrikeOut',
    'Squiggly',
]);

export function PdfAnnotator({ api, scope, path, workerUrl }: PdfAnnotatorProps) {
    const rawUrl = api.rohUrl(scope, path);
    if (GlobalWorkerOptions.workerSrc !== workerUrl) {
        GlobalWorkerOptions.workerSrc = workerUrl;
    }
    const containerRef = useRef<HTMLDivElement>(null);
    const [doc, setDoc] = useState<PDFDocumentProxy | null>(null);
    const [numPages, setNumPages] = useState(0);
    const [scale, setScale] = useState(1);
    const [annotations, setAnnotations] = useState<PdfAnnotation[]>([]);
    const [selection, setSelection] = useState<Selection | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [showList, setShowList] = useState(true);

    // Load the document + compute a fit-to-width scale from the first page.
    useEffect(() => {
        let cancelled = false;
        const task = getDocument({ url: rawUrl, withCredentials: true });
        task.promise
            .then(async (pdf) => {
                if (cancelled) return;
                const first = await pdf.getPage(1);
                const unscaled = first.getViewport({ scale: 1 });
                const avail = (containerRef.current?.clientWidth ?? 800) - 48;
                const fit = Math.min(2, Math.max(0.5, avail / unscaled.width));
                if (cancelled) return;
                setScale(fit);
                setNumPages(pdf.numPages);
                setDoc(pdf);
            })
            .catch((e) => {
                if (!cancelled)
                    setError(e?.message ?? 'PDF konnte nicht geladen werden.');
            });
        return () => {
            cancelled = true;
            task.destroy();
        };
    }, [rawUrl]);

    useEffect(() => {
        api.anmerkungen(scope, path)
            .then((r) => setAnnotations(r.annotations as unknown as PdfAnnotation[]))
            .catch(() => {
                /* listing failure shouldn't block viewing */
            });
    }, [api, scope, path]);

    const clearSelection = useCallback(() => {
        window.getSelection()?.removeAllRanges();
        setSelection(null);
    }, []);

    const handleMouseUp = useCallback(() => {
        const sel = window.getSelection();
        const container = containerRef.current;
        if (!sel || sel.isCollapsed || !container) {
            return;
        }
        const text = normalizeSelection(sel.toString());
        if (!text) {
            return;
        }
        const range = sel.getRangeAt(0);
        const node =
            range.startContainer.nodeType === Node.TEXT_NODE
                ? range.startContainer.parentElement
                : (range.startContainer as HTMLElement);
        const pageEl = node?.closest<HTMLElement>('[data-page]');
        if (!pageEl) {
            return;
        }
        const page = Number(pageEl.dataset.page);
        const rangeRect = range.getBoundingClientRect();
        const box = container.getBoundingClientRect();
        setSelection({
            page,
            text,
            x:
                rangeRect.left -
                box.left +
                container.scrollLeft +
                rangeRect.width / 2,
            y: rangeRect.top - box.top + container.scrollTop,
        });
    }, []);

    const submitHighlight = useCallback(
        async (color: AnnotationColor, note?: string) => {
            if (!selection) return;
            setBusy(true);
            setError(null);
            try {
                const r = await api.anmerkungAnlegen(scope, {
                    path,
                    action: 'highlight',
                    page: selection.page,
                    text: selection.text,
                    color,
                    ...(note ? { comment: note } : {}),
                });
                setAnnotations(r.annotations as unknown as PdfAnnotation[]);
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
        async (page: number, index: number) => {
            setBusy(true);
            setError(null);
            try {
                const r = await api.anmerkungEntfernen(scope, {
                    path,
                    page,
                    index,
                });
                setAnnotations(r.annotations as unknown as PdfAnnotation[]);
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

    const handleSetNote = useCallback(
        async (page: number, index: number, note: string) => {
            setBusy(true);
            setError(null);
            try {
                const r = await api.anmerkungAendern(scope, {
                    path,
                    page,
                    index,
                    note,
                });
                setAnnotations(r.annotations as unknown as PdfAnnotation[]);
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

    const jumpToPage = (page: number) => {
        containerRef.current
            ?.querySelector<HTMLElement>(`[data-page="${page}"]`)
            ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    const listItems: AnnotationListItem[] = annotations.map((a) => ({
        key: `${a.page}-${a.index}`,
        color: a.color,
        quote: TEXT_MARKUP.has(a.type) ? (a.text ?? null) : null,
        note: a.comment,
        context: `Seite ${a.page}`,
        onJump: () => jumpToPage(a.page),
        onDelete: () => handleDelete(a.page, a.index),
        onSaveNote: (note: string) => handleSetNote(a.page, a.index, note),
    }));

    if (error && !doc) {
        return (
            <div className="flex min-h-0 flex-1 items-center justify-center p-6 text-center text-sm text-destructive">
                {error}
            </div>
        );
    }

    return (
        <div className="flex min-h-0 flex-1">
            <div
                ref={containerRef}
                onMouseUp={handleMouseUp}
                className="relative min-h-0 flex-1 overflow-auto bg-muted/40"
            >
                {!doc && (
                    <div className="flex h-full items-center justify-center">
                        <p className="animate-pulse text-sm text-muted-foreground">
                            PDF wird geladen…
                        </p>
                    </div>
                )}

                <div className="flex flex-col items-center gap-4 py-4">
                    {doc &&
                        Array.from({ length: numPages }, (_, i) => (
                            <PdfPage
                                key={i + 1}
                                doc={doc}
                                pageNumber={i + 1}
                                scale={scale}
                                annotations={annotations.filter(
                                    (a) => a.page === i + 1,
                                )}
                            />
                        ))}
                </div>

                {selection && (
                    <AnnotationToolbar
                        x={selection.x}
                        y={selection.y}
                        busy={busy}
                        onHighlight={(color) => submitHighlight(color)}
                        onComment={(note, color) =>
                            submitHighlight(color, note)
                        }
                        onClose={clearSelection}
                    />
                )}
            </div>

            {/* Sidebar toggle */}
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

interface PdfPageProps {
    doc: PDFDocumentProxy;
    pageNumber: number;
    scale: number;
    annotations: PdfAnnotation[];
}

/**
 * A single page: renders its canvas + selectable text layer only once it scrolls
 * into view (IntersectionObserver), so a long book doesn't rasterise every page
 * up front. Existing marks overlay as positioned divs (rect × scale).
 */
function PdfPage({ doc, pageNumber, scale, annotations }: PdfPageProps) {
    const wrapRef = useRef<HTMLDivElement>(null);
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const textRef = useRef<HTMLDivElement>(null);
    const [size, setSize] = useState({ width: 0, height: 0 });
    const [rendered, setRendered] = useState(false);
    const [visible, setVisible] = useState(false);

    // Reveal when near the viewport.
    useEffect(() => {
        const el = wrapRef.current;
        if (!el) return;
        const io = new IntersectionObserver(
            (entries) => {
                if (entries.some((e) => e.isIntersecting)) {
                    setVisible(true);
                    io.disconnect();
                }
            },
            { rootMargin: '600px 0px' },
        );
        io.observe(el);
        return () => io.disconnect();
    }, []);

    // Pre-size the placeholder so scroll positions are stable before render.
    useEffect(() => {
        let cancelled = false;
        doc.getPage(pageNumber).then((page) => {
            if (cancelled) return;
            const vp = page.getViewport({ scale });
            setSize({ width: vp.width, height: vp.height });
        });
        return () => {
            cancelled = true;
        };
    }, [doc, pageNumber, scale]);

    // Render canvas + text layer once visible.
    useEffect(() => {
        if (!visible || rendered) return;
        let cancelled = false;
        let page: PDFPageProxy | null = null;

        doc.getPage(pageNumber).then(async (p) => {
            page = p;
            if (cancelled) return;
            const viewport = p.getViewport({ scale });
            const canvas = canvasRef.current;
            const textDiv = textRef.current;
            if (!canvas || !textDiv) return;

            const outputScale = window.devicePixelRatio || 1;
            canvas.width = Math.floor(viewport.width * outputScale);
            canvas.height = Math.floor(viewport.height * outputScale);
            canvas.style.width = `${viewport.width}px`;
            canvas.style.height = `${viewport.height}px`;

            await p.render({
                canvas,
                viewport,
                transform:
                    outputScale !== 1
                        ? [outputScale, 0, 0, outputScale, 0, 0]
                        : undefined,
            }).promise;
            if (cancelled) return;

            // Selectable text layer aligned over the canvas.
            textDiv.replaceChildren();
            textDiv.style.setProperty('--total-scale-factor', String(scale));
            const textContent = await p.getTextContent();
            const textLayer = new TextLayer({
                textContentSource: textContent,
                container: textDiv,
                viewport,
            });
            await textLayer.render();
            if (!cancelled) setRendered(true);
        });

        return () => {
            cancelled = true;
            page?.cleanup();
        };
    }, [visible, rendered, doc, pageNumber, scale]);

    return (
        <div
            ref={wrapRef}
            data-page={pageNumber}
            className="relative bg-white shadow-sm"
            style={{
                width: size.width || undefined,
                height: size.height || undefined,
            }}
        >
            <canvas ref={canvasRef} className="block" />
            <div
                ref={textRef}
                className="textLayer"
                style={{ width: size.width, height: size.height }}
            />

            {/* Existing marks: highlights as translucent fills, notes as a marker. */}
            {annotations.map((a) => {
                const [x0, y0, x1, y1] = a.rect;
                const isNote = !TEXT_MARKUP.has(a.type);
                const title = a.comment ?? a.text ?? a.type;
                return (
                    <div
                        key={a.index}
                        title={title ?? undefined}
                        className="pointer-events-none absolute z-10"
                        style={{
                            left: x0 * scale,
                            top: y0 * scale,
                            width: Math.max((x1 - x0) * scale, isNote ? 16 : 0),
                            height: Math.max(
                                (y1 - y0) * scale,
                                isNote ? 16 : 0,
                            ),
                            // Solid colour + multiply = a real highlighter: the
                            // page text stays readable, but the mark is clearly
                            // visible (a low-alpha fill washed out to near-white).
                            backgroundColor: isNote
                                ? undefined
                                : colorHex(a.color),
                            mixBlendMode: isNote ? undefined : 'multiply',
                        }}
                    >
                        {isNote && (
                            <span className="absolute -top-1 left-0 text-xs">
                                💬
                            </span>
                        )}
                    </div>
                );
            })}
        </div>
    );
}

export default PdfAnnotator;
