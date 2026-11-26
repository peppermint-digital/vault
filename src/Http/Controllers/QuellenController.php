<?php

namespace Peppermint\Vault\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Peppermint\Vault\Http\Bereichswahl;
use Peppermint\Vault\Quellen\Quellen;
use Peppermint\Vault\Support\Auslieferung;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Die JSON-Schiene fuer externe Quellen (`?scope=extern:<slug>`), nur mit
 * `vault.quellen`. Eigene Wege statt Sonderfaellen in DateienController:
 * Quellen haben keine Versionen, keinen Papierkorb und laden ebenenweise.
 */
class QuellenController extends Controller
{
    public function __construct(
        private readonly Quellen $quellen,
        private readonly Bereichswahl $wahl,
    ) {}

    public function ebene(Request $request): JsonResponse
    {
        $bereich = $this->wahl->aus($request);
        $quelle = $this->quellen->quelle($bereich);

        if (! $quelle->istErreichbar()) {
            // Ehrlich sagen statt eine leere Liste zu zeigen — leer sieht aus wie „Daten weg".
            return response()->json(['erreichbar' => false, 'eintraege' => []]);
        }

        return response()->json([
            'erreichbar' => true,
            'bereich' => $this->wahl->beschreibung($request, $bereich),
            'eintraege' => $this->quellen->ebene($bereich, trim((string) $request->query('path', ''), '/')),
        ]);
    }

    public function zeigen(Request $request): JsonResponse
    {
        $pfad = (string) $request->validate(['path' => 'required|string'])['path'];
        $inhalt = $this->quellen->lesen($this->wahl->aus($request), $pfad);

        return response()->json(['path' => $pfad, 'content' => $inhalt, 'version' => md5($inhalt), 'file_id' => null]);
    }

    public function roh(Request $request): Response
    {
        $pfad = (string) $request->validate(['path' => 'required|string'])['path'];

        try {
            $inhalt = $this->quellen->lesen($this->wahl->aus($request), $pfad);
        } catch (NotFoundHttpException) {
            throw new NotFoundHttpException;
        }

        return response($inhalt, 200, Auslieferung::kopf($pfad, $request->boolean('download')));
    }

    public function anlegen(Request $request): JsonResponse
    {
        $d = $request->validate(['path' => 'required|string', 'type' => 'required|in:file,folder', 'content' => 'nullable|string']);
        $bereich = $this->wahl->aus($request, schreiben: true);

        if (file_exists($this->quellen->absolut($bereich, $d['path']))) {
            return response()->json(['error' => 'Unter diesem Namen gibt es hier schon etwas. Bitte einen anderen waehlen.'], 422);
        }

        $d['type'] === 'folder'
            ? $this->quellen->ordnerAnlegen($bereich, $d['path'])
            : $this->quellen->schreiben($bereich, $d['path'], $d['content'] ?? '');

        return response()->json(['ok' => true, 'path' => trim($d['path'], '/'), 'file_id' => null]);
    }

    public function speichern(Request $request): JsonResponse
    {
        $d = $request->validate(['path' => 'required|string', 'content' => 'present|nullable|string', 'base_version' => 'nullable|string', 'force' => 'nullable|boolean']);
        $bereich = $this->wahl->aus($request, schreiben: true);
        $inhalt = (string) ($d['content'] ?? '');

        if (($d['base_version'] ?? null) !== null && ! ($d['force'] ?? false)) {
            $aktuell = file_exists($this->quellen->absolut($bereich, $d['path'])) ? md5($this->quellen->lesen($bereich, $d['path'])) : '';

            if ($aktuell !== $d['base_version']) {
                return response()->json([
                    'conflict' => true,
                    'current_content' => $aktuell === '' ? null : $this->quellen->lesen($bereich, $d['path']),
                    'current_version' => $aktuell,
                ], 409);
            }
        }

        $this->quellen->schreiben($bereich, $d['path'], $inhalt);

        return response()->json(['saved' => true, 'version' => md5($inhalt)]);
    }

    public function loeschen(Request $request): JsonResponse
    {
        $d = $request->validate(['path' => 'required|string']);
        $this->quellen->loeschen($this->wahl->aus($request, schreiben: true), $d['path']);

        return response()->json(['ok' => true, 'trash_id' => null]);
    }

    public function verschieben(Request $request): JsonResponse
    {
        $d = $request->validate(['from' => 'required|string', 'to' => 'required|string']);
        $this->quellen->verschieben($this->wahl->aus($request, schreiben: true), $d['from'], $d['to']);

        return response()->json(['ok' => true, 'path' => trim($d['to'], '/')]);
    }

    public function hochladen(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|max:'.(int) config('vault.hochladen.max_kb', 51200), 'folder' => 'nullable|string']);
        $pfad = $this->quellen->hochladen($this->wahl->aus($request, schreiben: true), (string) $request->input('folder', ''), $request->file('file'));

        return response()->json(['path' => $pfad, 'file_id' => null]);
    }
}
