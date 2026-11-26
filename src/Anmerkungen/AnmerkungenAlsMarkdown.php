<?php

namespace Peppermint\Vault\Anmerkungen;

use Peppermint\Vault\Bereich;
use Peppermint\Vault\Vault;

/**
 * Projects PDF/EPUB annotations into the document's Markdown steckbrief.
 *
 * This is a ONE-WAY projection: the PDF (PyMuPDF markup) and the EPUB sidecar
 * stay the source of truth; the markdown block is regenerated from them. We
 * write into the steckbrief `<stem>.md` (same convention the Avatar uses) so the
 * annotations become full-text searchable and editable around — only `.md`/`.html`
 * are indexed in the vault, so without this projection the markup is invisible to
 * search and to the Avatar's markdown-centric workflow.
 *
 * The injected block is scoped to the SOURCE filename (HTML-comment markers), so a
 * PDF and an EPUB that share a stem (e.g. book.pdf + book.epub → book.md) each keep
 * their own block instead of clobbering each other. Re-running replaces only that
 * source's block; surrounding steckbrief prose is never touched.
 *
 * Aus AI Brain `AnnotationMarkdownService` (Stand 118ee2b2), mit {@see Bereich}
 * statt VaultScope; die Marker heissen weiter `ai-brain:annot:`, damit
 * vorhandene Steckbriefe nach dem Wechsel aufs Paket erkannt werden.
 */
class AnmerkungenAlsMarkdown
{
    /** Human-readable colour swatches for the markdown rendering. */
    protected const SWATCHES = [
        'yellow' => '🟨', 'green' => '🟩', 'blue' => '🟦', 'red' => '🟥',
        'orange' => '🟧', 'pink' => '🩷', 'purple' => '🟪', 'cyan' => '🩵',
    ];

    public function __construct(
        protected PdfAnmerkungen $pdf,
        protected EpubAnmerkungen $epub,
        protected Vault $vault,
    ) {}

    /**
     * Regenerate the annotation block for `$sourcePath` inside its steckbrief.
     *
     * @return array{note:string,count:int}
     */
    public function sync(Bereich $scope, string $sourcePath): array
    {
        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));

        $extracted = match ($ext) {
            'pdf' => $this->pdf->lesen($scope, $sourcePath),
            'epub' => $this->epub->extract($scope, $sourcePath),
            default => null,
        };

        if ($extracted === null) {
            throw new \RuntimeException("Nur PDF/EPUB werden unterstützt: {$sourcePath}");
        }

        $notePath = $this->steckbriefPath($sourcePath);
        $block = $this->renderBlock($ext, $sourcePath, $extracted['annotations']);

        $this->vault->schreiben(
            $scope,
            $notePath,
            $this->mergeBlock($scope, $notePath, $sourcePath, $block)
        );

        return ['note' => $notePath, 'count' => $extracted['count']];
    }

    /** Steckbrief path: same directory, stem + ".md" (e.g. _attachments/book.pdf → _attachments/book.md). */
    public function steckbriefPath(string $sourcePath): string
    {
        $dir = dirname($sourcePath);
        $stem = pathinfo($sourcePath, PATHINFO_FILENAME);

        return ($dir === '' || $dir === '.') ? "{$stem}.md" : "{$dir}/{$stem}.md";
    }

    protected function markerStart(string $sourcePath): string
    {
        return '<!-- ai-brain:annot:'.basename($sourcePath).' -->';
    }

    protected function markerEnd(string $sourcePath): string
    {
        return '<!-- /ai-brain:annot:'.basename($sourcePath).' -->';
    }

    /**
     * Build the full marker block (markers + heading + rendered annotations).
     *
     * @param  array<int,array<string,mixed>>  $annotations
     */
    protected function renderBlock(string $ext, string $sourcePath, array $annotations): string
    {
        $base = basename($sourcePath);
        $lines = [
            $this->markerStart($sourcePath),
            "## 🖍 Markierungen & Notizen — `{$base}`",
            '',
            '_Automatisch aus den Annotationen erzeugt — diesen Block nicht von Hand editieren (wird überschrieben). '
                ."Quelle: [[{$sourcePath}]]_",
            '',
        ];

        if (empty($annotations)) {
            $lines[] = '_Noch keine Annotationen._';
        } elseif ($ext === 'pdf') {
            $lines = array_merge($lines, $this->renderPdf($annotations));
        } else {
            $lines = array_merge($lines, $this->renderEpub($annotations));
        }

        $lines[] = '';
        $lines[] = $this->markerEnd($sourcePath);

        return implode("\n", $lines);
    }

    /**
     * @param  array<int,array<string,mixed>>  $annotations
     * @return array<int,string>
     */
    protected function renderPdf(array $annotations): array
    {
        // Group by page so the note reads in document order.
        $byPage = [];
        foreach ($annotations as $a) {
            $byPage[$a['page'] ?? 0][] = $a;
        }
        ksort($byPage);

        $out = [];
        foreach ($byPage as $page => $items) {
            $out[] = "### Seite {$page}";
            foreach ($items as $a) {
                $out[] = $this->renderItem(
                    color: $a['color'] ?? null,
                    quote: $a['text'] ?? null,
                    note: $a['comment'] ?? null,
                    meta: array_filter([$a['type'] ?? null, $a['author'] ?? null]),
                );
            }
            $out[] = '';
        }

        return $out;
    }

    /**
     * @param  array<int,array<string,mixed>>  $annotations
     * @return array<int,string>
     */
    protected function renderEpub(array $annotations): array
    {
        $out = [];
        foreach ($annotations as $a) {
            $out[] = $this->renderItem(
                color: $a['color'] ?? null,
                quote: $a['quote'] ?? null,
                note: $a['note'] ?? null,
                meta: array_filter([$a['chapter'] ?? null, $a['author'] ?? null]),
            );
        }

        return $out;
    }

    /**
     * @param  array<int,string>  $meta
     */
    protected function renderItem(?string $color, ?string $quote, ?string $note, array $meta): string
    {
        $swatch = $color ? (self::SWATCHES[$color] ?? '•').' ' : '';
        $line = "- {$swatch}";
        $line .= $quote ? "„{$quote}\"" : '_(ohne Textbezug)_';
        if (! empty($note)) {
            $line .= " — 💬 {$note}";
        }
        if (! empty($meta)) {
            $line .= ' _('.implode(', ', $meta).')_';
        }

        return $line;
    }

    /**
     * Inject/replace the source-scoped block in the steckbrief, creating a minimal
     * note if none exists. Other content (incl. other sources' blocks) is preserved.
     */
    protected function mergeBlock(Bereich $scope, string $notePath, string $sourcePath, string $block): string
    {
        try {
            $existing = $this->vault->lesen($scope, $notePath);
        } catch (\Throwable) {
            $title = pathinfo($sourcePath, PATHINFO_FILENAME);

            return "# {$title}\n\n{$block}\n";
        }

        $start = preg_quote($this->markerStart($sourcePath), '/');
        $end = preg_quote($this->markerEnd($sourcePath), '/');
        $pattern = "/{$start}.*?{$end}/s";

        if (preg_match($pattern, $existing)) {
            return preg_replace($pattern, $block, $existing);
        }

        return rtrim($existing, "\n")."\n\n".$block."\n";
    }
}
