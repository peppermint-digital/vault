/**
 * Farben und Formen der PDF/EPUB-Anmerkungen (aus AI Brain `lib/annotations.ts`).
 * Die Anfragen laufen im Paket ueber `vaultApi` (anmerkungen…).
 *
 * The backend is text-anchored, not coordinate-based: for PDF we send the
 * selected text + page (PyMuPDF locates it), for EPUB the quote + chapter. Every
 * mutation returns the fresh, re-projected list so the caller re-renders in one
 * round-trip. Colours mirror scripts/pdf_annotations.py / EpubAnnotationService.
 */

export type AnnotationColor =
    | 'yellow'
    | 'green'
    | 'blue'
    | 'red'
    | 'orange'
    | 'pink'
    | 'purple'
    | 'cyan';

/** Swatch hexes match the RGB values PyMuPDF writes, so UI ≈ rendered colour. */
export const ANNOTATION_COLORS: {
    name: AnnotationColor;
    hex: string;
    label: string;
}[] = [
    { name: 'yellow', hex: '#FFEB3B', label: 'Gelb' },
    { name: 'green', hex: '#80D966', label: 'Grün' },
    { name: 'blue', hex: '#66B3FF', label: 'Blau' },
    { name: 'red', hex: '#FF7373', label: 'Rot' },
    { name: 'orange', hex: '#FFB34D', label: 'Orange' },
    { name: 'pink', hex: '#FF99D9', label: 'Pink' },
    { name: 'purple', hex: '#BF99F2', label: 'Lila' },
    { name: 'cyan', hex: '#80E5E5', label: 'Cyan' },
];

export function colorHex(color: AnnotationColor | null | undefined): string {
    return ANNOTATION_COLORS.find((c) => c.name === color)?.hex ?? '#FFEB3B';
}

export interface PdfAnnotation {
    page: number;
    index: number;
    type: string;
    author: string | null;
    comment: string | null;
    color: AnnotationColor | null;
    rect: [number, number, number, number];
    text?: string | null;
}

export interface EpubAnnotation {
    id: string;
    quote: string;
    note: string | null;
    color: AnnotationColor;
    chapter: string | null;
    author: string | null;
    created_at: string;
}

export type AnnotationType = 'pdf' | 'epub';

/** Collapse whitespace so a multi-line selection still matches the source text. */
export function normalizeSelection(text: string): string {
    return text.replace(/\s+/g, ' ').trim();
}
