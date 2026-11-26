<?php

namespace Peppermint\Vault\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Peppermint\Vault\Anmerkungen\AnmerkungenAlsMarkdown;
use Peppermint\Vault\Anmerkungen\EpubAnmerkungen;
use Peppermint\Vault\Anmerkungen\PdfAnmerkungen;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Http\Bereichswahl;
use Peppermint\Vault\Vault;
use Peppermint\Vault\Vorschaubilder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * PDF/EPUB-Anmerkungen aus der Oberflaeche und Vorschaubilder (AI Brain
 * `AnnotationController`, `VaultController::thumb`).
 *
 * Jede Aenderung wird in den Steckbrief `<stamm>.md` gespiegelt, damit sie
 * durchsuchbar ist. Abweichung von AI Brain: Der Bereich kommt aus `?scope=`
 * (dort nur privat). Ansehen verlangt Lesen, aendern Schreiben — eine
 * PDF-Markierung aendert die Datei selbst.
 */
class AnmerkungenController extends Controller
{
    private const FARBEN = 'yellow,green,blue,red,orange,pink,purple,cyan';

    public function __construct(
        private readonly PdfAnmerkungen $pdf,
        private readonly EpubAnmerkungen $epub,
        private readonly AnmerkungenAlsMarkdown $markdown,
        private readonly Bereichswahl $wahl,
    ) {}

    public function liste(Request $request): JsonResponse
    {
        $pfad = (string) $request->validate(['path' => 'required|string'])['path'];
        $bereich = $this->wahl->aus($request);

        return response()->json(['type' => $this->art($pfad), 'annotations' => $this->lesen($bereich, $pfad)['annotations']]);
    }

    public function anlegen(Request $request): JsonResponse
    {
        $d = $request->validate([
            'path' => 'required|string',
            'action' => 'nullable|in:highlight,comment',
            'page' => 'nullable|integer|min:1',
            'text' => 'nullable|string',
            'quote' => 'nullable|string',
            'note' => 'nullable|string',
            'comment' => 'nullable|string',
            'color' => 'nullable|in:'.self::FARBEN,
            'chapter' => 'nullable|string',
        ]);
        $bereich = $this->wahl->aus($request, schreiben: true);
        $pfad = $d['path'];
        $autor = (string) ($request->user()->name ?? '');
        $farbe = $d['color'] ?? 'yellow';

        if ($this->art($pfad) === 'pdf') {
            $seite = $d['page'] ?? throw ValidationException::withMessages(['page' => 'Seitenzahl ist fuer PDF-Anmerkungen erforderlich.']);

            if (($d['action'] ?? 'highlight') === 'comment') {
                $kommentar = $d['comment'] ?? null ?: throw ValidationException::withMessages(['comment' => 'Kommentartext ist erforderlich.']);
                $this->pdf->kommentieren($bereich, $pfad, $seite, $kommentar, $d['text'] ?? null, $autor);
            } else {
                $text = $d['text'] ?? null ?: throw ValidationException::withMessages(['text' => 'Markierter Text ist erforderlich.']);
                $this->pdf->markieren($bereich, $pfad, $seite, $text, $farbe, $d['comment'] ?? null, $autor);
            }
        } else {
            $zitat = $d['quote'] ?? null ?: throw ValidationException::withMessages(['quote' => 'Markierter Text (Zitat) ist erforderlich.']);
            $this->epub->add($bereich, $pfad, $zitat, $d['note'] ?? null, $farbe, $d['chapter'] ?? null, $autor);
        }

        return $this->antwort($bereich, $pfad);
    }

    public function aendern(Request $request): JsonResponse
    {
        $d = $request->validate([
            'path' => 'required|string',
            'page' => 'nullable|integer|min:1',
            'index' => 'nullable|integer|min:0',
            'id' => 'nullable|string',
            'note' => 'nullable|string',
            'color' => 'nullable|in:'.self::FARBEN,
        ]);
        $bereich = $this->wahl->aus($request, schreiben: true);

        if ($this->art($d['path']) === 'pdf') {
            if (! isset($d['page'], $d['index'])) {
                throw ValidationException::withMessages(['index' => 'page und index sind fuer PDF-Anmerkungen erforderlich.']);
            }
            $this->pdf->notizSetzen($bereich, $d['path'], $d['page'], $d['index'], $d['note'] ?? null);
        } else {
            $id = $d['id'] ?? null ?: throw ValidationException::withMessages(['id' => 'id ist fuer EPUB-Anmerkungen erforderlich.']);
            $this->epub->update($bereich, $d['path'], $id, $d['note'] ?? null, $d['color'] ?? null);
        }

        return $this->antwort($bereich, $d['path']);
    }

    public function entfernen(Request $request): JsonResponse
    {
        $d = $request->validate([
            'path' => 'required|string',
            'page' => 'nullable|integer|min:1',
            'index' => 'nullable|integer|min:0',
            'id' => 'nullable|string',
        ]);
        $bereich = $this->wahl->aus($request, schreiben: true);

        if ($this->art($d['path']) === 'pdf') {
            if (! isset($d['page'], $d['index'])) {
                throw ValidationException::withMessages(['index' => 'page und index sind fuer PDF-Anmerkungen erforderlich.']);
            }
            $this->pdf->entfernen($bereich, $d['path'], $d['page'], $d['index']);
        } else {
            $id = $d['id'] ?? null ?: throw ValidationException::withMessages(['id' => 'id ist fuer EPUB-Anmerkungen erforderlich.']);
            $this->epub->remove($bereich, $d['path'], $id);
        }

        return $this->antwort($bereich, $d['path']);
    }

    public function vorschau(Request $request, Vault $vault, Vorschaubilder $bilder): BinaryFileResponse
    {
        $pfad = (string) $request->validate(['path' => 'required|string'])['path'];
        $bereich = $this->wahl->aus($request);
        $bild = $bilder->vorschau($bereich, $pfad, $vault->absolut($bereich, $pfad));

        if ($bild === null) {
            throw new NotFoundHttpException;
        }

        return response()->file($bild, [
            'Content-Type' => str_ends_with($bild, '.svg') ? 'image/svg+xml' : 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    private function antwort(Bereich $bereich, string $pfad): JsonResponse
    {
        $gespiegelt = $this->markdown->sync($bereich, $pfad);

        return response()->json([
            'type' => $this->art($pfad),
            'annotations' => $this->lesen($bereich, $pfad)['annotations'],
            'steckbrief' => $gespiegelt['note'],
        ]);
    }

    /** @return array{count: int, annotations: array<int, array<string, mixed>>} */
    private function lesen(Bereich $bereich, string $pfad): array
    {
        return $this->art($pfad) === 'pdf' ? $this->pdf->lesen($bereich, $pfad) : $this->epub->extract($bereich, $pfad);
    }

    private function art(string $pfad): string
    {
        return match (strtolower(pathinfo($pfad, PATHINFO_EXTENSION))) {
            // Ohne PyMuPDF eine lesbare Absage statt eines 500ers mitten im Klick.
            'pdf' => PdfAnmerkungen::verfuegbar() ? 'pdf' : throw new HttpException(503, 'PDF-Anmerkungen sind hier nicht eingerichtet.'),
            'epub' => 'epub',
            default => throw ValidationException::withMessages(['path' => 'Nur PDF- und EPUB-Dateien lassen sich anmerken.']),
        };
    }
}
