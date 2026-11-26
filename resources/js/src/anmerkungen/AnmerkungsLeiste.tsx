import { MessageSquarePlus, X } from 'lucide-react';
import { useState } from 'react';
import { ANNOTATION_COLORS, type AnnotationColor } from './farben';

interface AnnotationToolbarProps {
    /** Position within the (relatively positioned) scroll container, in px. */
    x: number;
    y: number;
    busy?: boolean;
    /** Highlight (PDF/EPUB) the current selection with a colour. */
    onHighlight: (color: AnnotationColor) => void;
    /** Attach a note (optionally coloured) to the current selection. */
    onComment: (note: string, color: AnnotationColor) => void;
    onClose: () => void;
}

/**
 * Floating toolbar shown next to a text selection: a row of colour swatches for
 * a quick highlight, plus a note button that expands into a small textarea.
 * Shared by the PDF and EPUB annotators — both feed it a selection.
 */
export default function AnnotationToolbar({
    x,
    y,
    busy = false,
    onHighlight,
    onComment,
    onClose,
}: AnnotationToolbarProps) {
    const [noteMode, setNoteMode] = useState(false);
    const [note, setNote] = useState('');
    const [color, setColor] = useState<AnnotationColor>('yellow');

    return (
        <div
            // Keep mousedown from clearing the window selection before we read it.
            onMouseDown={(e) => e.preventDefault()}
            className="absolute z-30 -translate-x-1/2 -translate-y-full rounded-lg border bg-popover p-1.5 shadow-md"
            style={{ left: x, top: y - 8 }}
        >
            {noteMode ? (
                <div className="flex w-64 flex-col gap-2 p-1">
                    <textarea
                        autoFocus
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                        placeholder="Notiz zur Markierung…"
                        rows={3}
                        className="w-full resize-none rounded-md border bg-background p-2 text-sm outline-none focus:ring-1 focus:ring-ring"
                    />
                    <div className="flex items-center justify-between">
                        <div className="flex gap-1">
                            {ANNOTATION_COLORS.map((c) => (
                                <button
                                    key={c.name}
                                    type="button"
                                    title={c.label}
                                    onClick={() => setColor(c.name)}
                                    className={`h-4 w-4 rounded-full border transition-transform hover:scale-110 ${
                                        color === c.name
                                            ? 'ring-2 ring-ring ring-offset-1'
                                            : ''
                                    }`}
                                    style={{ backgroundColor: c.hex }}
                                />
                            ))}
                        </div>
                        <button
                            type="button"
                            disabled={busy || !note.trim()}
                            onClick={() => onComment(note.trim(), color)}
                            className="rounded-md bg-primary px-2 py-1 text-xs font-medium text-primary-foreground disabled:opacity-50"
                        >
                            Speichern
                        </button>
                    </div>
                </div>
            ) : (
                <div className="flex items-center gap-1">
                    {ANNOTATION_COLORS.map((c) => (
                        <button
                            key={c.name}
                            type="button"
                            title={`Markieren – ${c.label}`}
                            disabled={busy}
                            onClick={() => onHighlight(c.name)}
                            className="h-5 w-5 rounded-full border transition-transform hover:scale-110 disabled:opacity-50"
                            style={{ backgroundColor: c.hex }}
                        />
                    ))}
                    <span className="mx-0.5 h-5 w-px bg-border" />
                    <button
                        type="button"
                        title="Notiz hinzufügen"
                        disabled={busy}
                        onClick={() => setNoteMode(true)}
                        className="rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-50"
                    >
                        <MessageSquarePlus className="h-4 w-4" />
                    </button>
                    <button
                        type="button"
                        title="Schließen"
                        onClick={onClose}
                        className="rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>
            )}
        </div>
    );
}
