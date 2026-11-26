<?php

namespace Peppermint\Vault\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Peppermint\Vault\EuroOffice\Blankovorlagen;
use Peppermint\Vault\EuroOffice\Dateiart;
use Peppermint\Vault\Http\Bereichswahl;
use Peppermint\Vault\Support\Auslieferung;
use Peppermint\Vault\Vault;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Die JSON-Schiene der Vault-Oberflaeche (AI Brain `VaultController`,
 * ohne Inertia). Feldnamen wie dort: `path`, `type`, `content`,
 * `base_version`, `force`, `from`, `to`.
 */
class DateienController extends Controller
{
    public function __construct(
        private readonly Vault $vault,
        private readonly Bereichswahl $wahl,
    ) {}

    public function baum(Request $request): JsonResponse
    {
        $bereich = $this->wahl->aus($request);

        return response()->json([
            'bereich' => $this->wahl->beschreibung($request, $bereich),
            'baum' => $this->vault->baum($bereich),
        ]);
    }

    public function zeigen(Request $request): JsonResponse
    {
        $request->validate(['path' => 'required|string']);
        $bereich = $this->wahl->aus($request);
        $pfad = (string) $request->query('path');
        $inhalt = $this->vault->lesen($bereich, $pfad);

        return response()->json([
            'path' => $pfad,
            'content' => $inhalt,
            'version' => md5($inhalt),
            'file_id' => $this->vault->kennung()->sicherstellen($bereich, $pfad),
        ]);
    }

    public function roh(Request $request): Response
    {
        $request->validate(['path' => 'required|string']);
        $bereich = $this->wahl->aus($request);
        $pfad = (string) $request->query('path');

        try {
            $inhalt = $this->vault->lesen($bereich, $pfad);
        } catch (\Throwable) {
            throw new NotFoundHttpException;
        }

        return response($inhalt, 200, Auslieferung::kopf($pfad, $request->boolean('download')));
    }

    /** Anlegen legt an. Es ueberschreibt nicht (AI Brain #6293). */
    public function anlegen(Request $request): JsonResponse
    {
        $daten = $request->validate([
            'path' => 'required|string',
            'type' => 'required|in:file,folder',
            'content' => 'nullable|string',
        ]);
        $bereich = $this->wahl->aus($request, schreiben: true);

        if ($this->vault->existiert($bereich, $daten['path'])) {
            return response()->json(['error' => 'Unter diesem Namen gibt es hier schon etwas. Bitte einen anderen waehlen.'], 422);
        }

        if ($daten['type'] === 'folder') {
            $this->vault->ordnerAnlegen($bereich, $daten['path']);

            return response()->json(['ok' => true, 'path' => trim($daten['path'], '/')]);
        }

        // Office-Dateien entstehen als Kopie einer leeren Vorlage (AI Brain #6298):
        // Eine .xlsx mit null Bytes ist kein Dokument, sondern ein kaputtes Archiv.
        if (Dateiart::fuer($daten['path']) === Dateiart::OFFICE) {
            $vorlage = Blankovorlagen::pfadFuer($daten['path']);

            if ($vorlage === null) {
                $endung = strtolower(pathinfo($daten['path'], PATHINFO_EXTENSION));

                return response()->json(['error' => "Neue .{$endung}-Dateien koennen hier nicht angelegt werden. Moeglich sind "
                    .implode(', ', array_map(fn (string $e): string => '.'.$e, Blankovorlagen::endungen()))
                    ." — oeffnen und bearbeiten laesst sich .{$endung} weiterhin."], 422);
            }

            $pfad = $this->vault->binaerSchreiben($bereich, $daten['path'], $vorlage, $this->autor($request));

            return response()->json(['ok' => true, 'path' => $pfad, 'file_id' => $this->vault->kennung()->id($bereich, $pfad)]);
        }

        $pfad = $this->vault->schreiben($bereich, $daten['path'], $daten['content'] ?? '', $this->autor($request));

        return response()->json(['ok' => true, 'path' => $pfad, 'file_id' => $this->vault->kennung()->id($bereich, $pfad)]);
    }

    /** Optimistische Konflikterkennung, wenn `base_version` mitkommt. */
    public function speichern(Request $request): JsonResponse
    {
        $daten = $request->validate([
            'path' => 'required|string',
            'content' => 'present|nullable|string',
            'base_version' => 'nullable|string',
            'force' => 'nullable|boolean',
        ]);
        $bereich = $this->wahl->aus($request, schreiben: true);
        $pfad = $daten['path'];
        $inhalt = (string) ($daten['content'] ?? '');

        if (($daten['base_version'] ?? null) !== null && ! ($daten['force'] ?? false)) {
            $aktuell = $this->vault->existiert($bereich, $pfad) ? md5($this->vault->lesen($bereich, $pfad)) : '';

            if ($aktuell !== $daten['base_version']) {
                return response()->json([
                    'conflict' => true,
                    'current_content' => $aktuell === '' ? null : $this->vault->lesen($bereich, $pfad),
                    'current_version' => $aktuell,
                ], 409);
            }
        }

        $this->vault->schreiben($bereich, $pfad, $inhalt, $this->autor($request));

        return response()->json(['saved' => true, 'version' => md5($inhalt)]);
    }

    public function loeschen(Request $request): JsonResponse
    {
        $daten = $request->validate(['path' => 'required|string']);
        $bereich = $this->wahl->aus($request, schreiben: true);

        $eintrag = $this->vault->loeschen($bereich, $daten['path'], (int) $request->user()->getAuthIdentifier());

        return response()->json(['ok' => true, 'trash_id' => $eintrag->id]);
    }

    public function verschieben(Request $request): JsonResponse
    {
        $daten = $request->validate(['from' => 'required|string', 'to' => 'required|string']);
        $bereich = $this->wahl->aus($request, schreiben: true);

        $this->vault->verschieben($bereich, $daten['from'], $daten['to']);

        return response()->json(['ok' => true, 'path' => trim($daten['to'], '/')]);
    }

    public function hochladen(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:'.(int) config('vault.hochladen.max_kb', 51200),
            'folder' => 'nullable|string',
        ]);
        $bereich = $this->wahl->aus($request, schreiben: true);

        $pfad = $this->vault->hochladen($bereich, (string) $request->input('folder', ''), $request->file('file'), $this->autor($request));

        return response()->json(['path' => $pfad, 'file_id' => $this->vault->kennung()->id($bereich, $pfad)]);
    }

    public function suchen(Request $request): JsonResponse
    {
        $bereich = $this->wahl->aus($request);
        $suche = trim((string) $request->query('q', ''));

        return response()->json($suche === '' ? [] : $this->vault->suchen($bereich, $suche, 30));
    }

    public function verweise(Request $request): JsonResponse
    {
        $bereich = $this->wahl->aus($request);
        $pfad = trim((string) $request->query('path', ''));

        return response()->json($pfad === ''
            ? $this->vault->verweise()->graph($bereich)
            : ['backlinks' => $this->vault->verweise()->rueckverweise($bereich, $pfad)]);
    }

    private function autor(Request $request): string
    {
        $user = $request->user();
        $name = $user->name ?? null;

        return is_string($name) && $name !== '' ? $name : (string) $user->getAuthIdentifier();
    }
}
