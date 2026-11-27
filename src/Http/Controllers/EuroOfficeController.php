<?php

namespace Peppermint\Vault\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Peppermint\Vault\Ablage;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Contracts\Vaultrechte;
use Peppermint\Vault\EuroOffice\Client;
use Peppermint\Vault\EuroOffice\Dateiart;
use Peppermint\Vault\EuroOffice\Konfiguration;
use Peppermint\Vault\Http\Bereichswahl;
use Peppermint\Vault\Quellen\Quellen;
use Peppermint\Vault\Vault;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * EuroOffice: Editor-Konfiguration, Datei abholen, Rueckweg (AI Brain
 * `EuroOfficeDocumentController`, `EuroOfficeCallbackController`, #6217/#6218).
 *
 * - `konfiguration` laeuft in der Sitzung und prueft die Rechte am Bereich.
 * - `datei` und `rueckweg` haben KEINE Sitzung, weil der Document Server keine
 *   hat. Sie sind ueber UNSER signiertes Dokument-Token in der Adresse
 *   gesichert. Darin steht das Ziel — der Rueckweg glaubt dem Server nie,
 *   wohin er schreiben soll.
 *
 * Drei Absicherungen am Rueckweg, je gegen einen eigenen Angriff: Ziel im
 * eigenen Token; Nutzlast mit dem gemeinsamen Geheimnis signiert; Abholadresse
 * muss zu UNSEREM Document Server gehoeren (sonst SSRF).
 *
 * Zusaetzlich zu AI Brain: Das Rueckweg-Token traegt die Person, und beim
 * Speichern wird ueber Vaultrechte geprueft, ob sie noch schreiben darf. In AI
 * Brain reichte das Token bis zu zwoelf Stunden, auch nachdem jemand aus dem
 * Raum genommen wurde.
 */
class EuroOfficeController extends Controller
{
    private const STATUS_EDITING = 1;

    private const STATUS_SAVE = 2;

    private const STATUS_SAVE_ERROR = 3;

    private const STATUS_CLOSED_NO_CHANGES = 4;

    private const STATUS_FORCE_SAVE = 6;

    private const STATUS_FORCE_SAVE_ERROR = 7;

    public function __construct(
        private readonly Client $client,
        private readonly Ablage $ablage,
    ) {}

    public function konfiguration(Request $request, Bereichswahl $wahl, Konfiguration $bauer, Vaultrechte $rechte): JsonResponse
    {
        $pfad = (string) $request->validate(['path' => 'required|string'])['path'];
        $endung = strtolower(pathinfo($pfad, PATHINFO_EXTENSION));

        if (Dateiart::fuer($pfad) !== Dateiart::OFFICE) {
            return response()->json(['error' => "Dateityp .{$endung} wird vom Editor nicht unterstuetzt."], 422);
        }

        if (! $this->client->isConfigured()) {
            // Die Aufschluesselung mitgeben: Ein weisser Editor ohne Grund ist die schlechteste Rueckmeldung.
            return response()->json(['error' => 'EuroOffice ist nicht einsatzbereit.', 'health' => $this->client->health()], 503);
        }

        $bereich = $wahl->aus($request);

        // Quellen (0.7.1): Pfad ueber Quellen statt Ablage; nicht eingehaengt -> 403 mit Grund.
        $absolut = $this->absolutFuer($bereich, $pfad);

        if (! is_file($absolut)) {
            throw new NotFoundHttpException;
        }

        return response()->json($bauer->bauen($bereich, $pfad, $request->user(), $rechte->darfSchreiben($request->user(), $bereich), $absolut));
    }

    public function datei(string $token): BinaryFileResponse
    {
        if (! $this->client->isConfigured()) {
            throw new NotFoundHttpException;
        }

        $ziel = $this->client->readDocumentToken($token) ?? throw new AccessDeniedHttpException('Ungueltiges Token.');
        $absolut = $this->absolutFuer(Bereich::aus($ziel['scope']), $ziel['path']);

        if (! is_file($absolut)) {
            throw new NotFoundHttpException;
        }

        return response()->file($absolut, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function rueckweg(Request $request, string $token, Vault $vault): JsonResponse
    {
        if (! $this->client->isConfigured()) {
            Log::warning('vault EuroOffice: Rueckweg bei unvollstaendiger Konfiguration');

            return $this->abweisen('EuroOffice ist nicht konfiguriert.');
        }

        $ziel = $this->client->readDocumentToken($token);
        if ($ziel === null) {
            Log::warning('vault EuroOffice: Rueckweg mit ungueltigem Dokument-Token abgelehnt');

            return $this->abweisen('Ungueltiges Token.');
        }

        if (! $this->nutzlastEcht($request)) {
            Log::warning('vault EuroOffice: Rueckweg mit ungueltiger Signatur abgelehnt');

            return $this->abweisen('Signatur ungueltig.');
        }

        $status = (int) $request->input('status', 0);

        if (in_array($status, [self::STATUS_SAVE, self::STATUS_FORCE_SAVE], true) && ! $this->darfNochSchreiben($token, $ziel)) {
            Log::warning('vault EuroOffice: Rueckweg abgelehnt, die Person darf in diesem Bereich nicht (mehr) schreiben');

            return $this->abweisen('Keine Schreibrechte mehr.');
        }

        return match ($status) {
            self::STATUS_SAVE, self::STATUS_FORCE_SAVE => $this->speichern($request, $vault, $ziel),
            // Der Server konnte selbst nicht speichern: protokollieren, nicht still bestaetigen.
            self::STATUS_SAVE_ERROR, self::STATUS_FORCE_SAVE_ERROR => $this->serverfehler($status),
            self::STATUS_EDITING, self::STATUS_CLOSED_NO_CHANGES => $this->ok(),
            default => $this->ok(),
        };
    }

    /** @param  array{scope: string, path: string}  $ziel */
    private function speichern(Request $request, Vault $vault, array $ziel): JsonResponse
    {
        $url = (string) $request->input('url', '');

        if ($url === '') {
            return $this->abweisen('Keine Datei-Adresse in der Nutzlast.');
        }

        if (! $this->client->isOwnDocumentServerUrl($url)) {
            Log::warning('vault EuroOffice: Datei-Adresse zeigt nicht auf den eigenen Document Server', ['host' => parse_url($url, PHP_URL_HOST)]);

            return $this->abweisen('Datei-Adresse nicht zulaessig.');
        }

        $temp = tempnam(sys_get_temp_dir(), 'vault-eurooffice-');
        if ($temp === false) {
            return $this->abweisen('Kein Zwischenspeicher verfuegbar.');
        }

        try {
            $antwort = Http::timeout(120)->withoutVerifying()->get($this->client->abrufAdresse($url));

            if (! $antwort->successful()) {
                Log::error('vault EuroOffice: Datei konnte nicht geladen werden', ['http' => $antwort->status()]);

                return $this->abweisen('Datei konnte nicht geladen werden.');
            }

            if ($antwort->body() === '') {
                Log::error('vault EuroOffice: leere Datei erhalten — NICHT geschrieben');

                return $this->abweisen('Leere Datei erhalten.');
            }

            file_put_contents($temp, $antwort->body());
            $bereich = Bereich::aus($ziel['scope']);
            if ($bereich->istQuelle()) {
                // Fremder Speicher: direkt dorthin, ohne Versionen. Ist die Quelle weg, wirft
                // Quellen::absolut (403) -> abgelehnt; NIE Ausweichen in den Vault-Speicher
                // (AI Brain #1625).
                app(Quellen::class)->binaerSchreiben($bereich, $ziel['path'], $temp);
            } else {
                $vault->binaerSchreiben($bereich, $ziel['path'], $temp, $this->autor($request));
            }

            return $this->ok();
        } catch (\Throwable $e) {
            Log::error('vault EuroOffice: Speichern fehlgeschlagen', ['error' => $e->getMessage()]);

            return $this->abweisen('Speichern fehlgeschlagen.');
        } finally {
            @unlink($temp);
        }
    }

    /** Wo die Datei liegt: Quellen ueber Quellen::absolut (prueft Einhaengung), sonst die Ablage. */
    private function absolutFuer(Bereich $bereich, string $pfad): string
    {
        return $bereich->istQuelle() ? app(Quellen::class)->absolut($bereich, $pfad) : $this->ablage->aufloesen($bereich, $pfad);
    }

    /** @param  array{scope: string, path: string}  $ziel */
    private function darfNochSchreiben(string $token, array $ziel): bool
    {
        $kennung = (string) ($this->client->verify($token)['user'] ?? '');
        $modell = (string) config('vault.user_model');
        $person = $kennung === '' ? null : $modell::query()->find($kennung);

        return $person !== null && app(Vaultrechte::class)->darfSchreiben($person, Bereich::aus($ziel['scope']));
    }

    private function serverfehler(int $status): JsonResponse
    {
        Log::error('vault EuroOffice: Document Server meldet einen Speicherfehler', ['status' => $status]);

        return $this->ok();
    }

    private function nutzlastEcht(Request $request): bool
    {
        $ausKopf = $this->client->tokenFromHeader($request->header($this->client->jwtHeader()));
        $ausRumpf = $request->input('token');

        foreach ([$ausKopf, is_string($ausRumpf) ? $ausRumpf : null] as $kandidat) {
            if (is_string($kandidat) && $kandidat !== '' && $this->client->verify($kandidat) !== null) {
                return true;
            }
        }

        return false;
    }

    private function autor(Request $request): string
    {
        $nutzer = $request->input('users');

        return is_array($nutzer) && $nutzer !== [] && is_scalar($nutzer[0])
            ? 'eurooffice:'.mb_substr((string) $nutzer[0], 0, 64)
            : 'eurooffice';
    }

    private function ok(): JsonResponse
    {
        return response()->json(['error' => 0]);
    }

    /** `error: 1`: Der Server versucht es erneut, statt die Bearbeitung zu verwerfen. */
    private function abweisen(string $grund): JsonResponse
    {
        return response()->json(['error' => 1, 'message' => $grund]);
    }
}
