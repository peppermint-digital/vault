<?php

namespace Peppermint\Vault\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Peppermint\Vault\Http\Bereichswahl;
use Peppermint\Vault\Models\VaultShare;
use Peppermint\Vault\Vault;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Freigabelinks verwalten (AI Brain `NoteShareController`: forPath, store,
 * destroy). Die oeffentliche Ansicht `/geteilt/{token}` folgt mit der Seite.
 *
 * Abweichung von AI Brain: Einen Link anlegen darf nur, wer im Bereich
 * SCHREIBEN darf. In AI Brain reichte Lesen — im Firmen-Vault des Managers
 * liest jede Person alles, und ein Link macht eine Datei oeffentlich.
 */
class FreigabenController extends Controller
{
    public function __construct(
        private readonly Vault $vault,
        private readonly Bereichswahl $wahl,
    ) {}

    public function fuerPfad(Request $request): JsonResponse
    {
        $daten = $request->validate(['path' => 'required|string']);
        $bereich = $this->wahl->aus($request);

        $links = VaultShare::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->where('vault_path', $daten['path'])
            ->when($bereich->istPersoenlich(), fn ($q) => $q->whereNull('area_key'), fn ($q) => $q->where('area_key', $bereich->schluessel))
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest()
            ->get();

        return response()->json(['shares' => $links->map(fn (VaultShare $s) => $this->darstellung($s))->values()]);
    }

    public function anlegen(Request $request): JsonResponse
    {
        $daten = $request->validate([
            'path' => 'required|string',
            'password' => 'nullable|string|min:4|max:200',
            'expires_in_days' => 'nullable|integer|min:1|max:3650',
        ]);
        $bereich = $this->wahl->aus($request, schreiben: true);

        if (! is_file($this->vault->absolut($bereich, $daten['path']))) {
            throw new NotFoundHttpException('Datei nicht gefunden.');
        }

        $link = VaultShare::query()->create([
            'user_id' => $request->user()->getAuthIdentifier(),
            'vault_path' => trim($daten['path'], '/'),
            'area_key' => $bereich->istPersoenlich() ? null : $bereich->schluessel,
            'password_hash' => isset($daten['password']) ? Hash::make($daten['password']) : null,
            'expires_at' => isset($daten['expires_in_days']) ? now()->addDays((int) $daten['expires_in_days']) : null,
        ]);

        return response()->json(['share' => $this->darstellung($link)], 201);
    }

    /** Zurueckziehen statt loeschen: Der Aufrufzaehler bleibt nachvollziehbar. */
    public function zurueckziehen(Request $request, int $freigabe): JsonResponse
    {
        $link = VaultShare::query()->findOrFail($freigabe);

        if ((string) $link->user_id !== (string) $request->user()->getAuthIdentifier()) {
            throw new AccessDeniedHttpException;
        }

        $link->forceFill(['revoked_at' => now()])->save();

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function darstellung(VaultShare $s): array
    {
        $praefix = trim((string) config('vault.routen.freigabe_praefix', 'geteilt'), '/');

        return [
            'id' => $s->id,
            'path' => $s->vault_path,
            'url' => url($praefix.'/'.$s->token),
            'status' => $s->status(),
            'requires_password' => $s->requiresPassword(),
            'expires_at' => $s->expires_at?->toIso8601String(),
            'view_count' => $s->view_count,
        ];
    }
}
