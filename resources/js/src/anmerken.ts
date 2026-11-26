/**
 * Eigener Einstieg fuer den PDF-Annotator, weil er `pdfjs-dist` braucht.
 *
 * Im Haupteinstieg wuerde der Import jedes Produkt zwingen, pdf.js zu
 * installieren — auch den Manager, der nur Notizen zeigt. Hier zahlt nur, wer
 * PDFs anmerken will: `import { PdfAnnotator } from '@peppermint-digital/vault/anmerken'`.
 */
export * from './anmerkungen/PdfAnnotator';
