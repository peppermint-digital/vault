import { MessageSquarePlus, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { colorHex, type AnnotationColor } from './farben';

export interface AnnotationListItem {
    key: string;
    color: AnnotationColor | null;
    /** The quoted/marked text (may be empty for an unanchored note). */
    quote: string | null;
    /** Optional note/comment attached to the mark. */
    note: string | null;
    /** Context label, e.g. "Seite 3" or a chapter name. */
    context: string | null;
    onJump?: () => void;
    onDelete: () => void;
    /** Add/change the note on this existing mark (omit to disable editing). */
    onSaveNote?: (note: string) => void;
}

interface AnnotationListProps {
    items: AnnotationListItem[];
    busy?: boolean;
}

/**
 * Right-hand list of all marks in the current document. Shared by both viewers;
 * each annotator maps its native annotations onto AnnotationListItem. Each row
 * can add/edit its note inline when the annotator provides `onSaveNote`.
 */
export default function AnnotationList({ items, busy }: AnnotationListProps) {
    if (items.length === 0) {
        return (
            <div className="p-4 text-center text-xs text-muted-foreground">
                Noch keine Markierungen. Text markieren, um eine hinzuzufügen.
            </div>
        );
    }

    return (
        <ul className="flex flex-col divide-y">
            {items.map((item) => (
                <Row key={item.key} item={item} busy={busy} />
            ))}
        </ul>
    );
}

function Row({ item, busy }: { item: AnnotationListItem; busy?: boolean }) {
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(item.note ?? '');

    const startEdit = () => {
        setDraft(item.note ?? '');
        setEditing(true);
    };

    const save = () => {
        item.onSaveNote?.(draft.trim());
        setEditing(false);
    };

    return (
        <li className="group flex flex-col gap-2 px-3 py-2 hover:bg-muted/50">
            <div className="flex items-start gap-2">
                <span
                    className="mt-1 h-3 w-3 shrink-0 rounded-full border"
                    style={{ backgroundColor: colorHex(item.color) }}
                />
                <button
                    type="button"
                    onClick={item.onJump}
                    disabled={!item.onJump}
                    className="min-w-0 flex-1 text-left enabled:cursor-pointer"
                >
                    {item.context && (
                        <span className="block text-3xs font-medium tracking-wide text-muted-foreground uppercase">
                            {item.context}
                        </span>
                    )}
                    {item.quote ? (
                        <span className="line-clamp-2 text-xs text-foreground">
                            „{item.quote}"
                        </span>
                    ) : (
                        <span className="text-xs text-muted-foreground italic">
                            (ohne Textbezug)
                        </span>
                    )}
                    {item.note && !editing && (
                        <span className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">
                            💬 {item.note}
                        </span>
                    )}
                </button>
                <div className="flex shrink-0 items-center gap-0.5 opacity-0 transition-opacity group-hover:opacity-100">
                    {item.onSaveNote && !editing && (
                        <button
                            type="button"
                            title={
                                item.note
                                    ? 'Notiz bearbeiten'
                                    : 'Notiz hinzufügen'
                            }
                            disabled={busy}
                            onClick={startEdit}
                            className="rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-50"
                        >
                            {item.note ? (
                                <Pencil className="h-3.5 w-3.5" />
                            ) : (
                                <MessageSquarePlus className="h-3.5 w-3.5" />
                            )}
                        </button>
                    )}
                    <button
                        type="button"
                        title="Markierung entfernen"
                        disabled={busy}
                        onClick={item.onDelete}
                        className="rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-destructive disabled:opacity-50"
                    >
                        <Trash2 className="h-3.5 w-3.5" />
                    </button>
                </div>
            </div>

            {editing && (
                <div className="flex flex-col gap-1.5 pl-5">
                    <textarea
                        autoFocus
                        value={draft}
                        onChange={(e) => setDraft(e.target.value)}
                        placeholder="Notiz zur Markierung…"
                        rows={2}
                        className="w-full resize-none rounded-md border bg-background p-1.5 text-xs outline-none focus:ring-1 focus:ring-ring"
                    />
                    <div className="flex justify-end gap-1.5">
                        <button
                            type="button"
                            onClick={() => setEditing(false)}
                            className="rounded-md px-2 py-0.5 text-xs text-muted-foreground hover:bg-muted"
                        >
                            Abbrechen
                        </button>
                        <button
                            type="button"
                            disabled={busy}
                            onClick={save}
                            className="rounded-md bg-primary px-2 py-0.5 text-xs font-medium text-primary-foreground disabled:opacity-50"
                        >
                            Speichern
                        </button>
                    </div>
                </div>
            )}
        </li>
    );
}
