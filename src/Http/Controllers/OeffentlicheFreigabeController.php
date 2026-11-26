<?php

namespace Peppermint\Vault\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Peppermint\Vault\Ablage;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Models\VaultShare;
use Peppermint\Vault\Support\Auslieferung;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Die oeffentliche Seite eines Freigabelinks — ohne Anmeldung (AI Brain
 * `NoteShareController::show/raw/unlock`).
 *
 * Die Seite selbst (`/<freigabe_praefix>/{token}`) rendert das Produkt, mit
 * dem npm-Baustein `GeteilteAnsicht`. Hier liegt nur, was sie braucht:
 *
 * - `info`: Name, Format und — wenn offen — der Inhalt (zaehlt den Aufruf);
 * - `entsperren`: Passwort pruefen, in der Sitzung merken (gedrosselt);
 * - `roh`: die Datei selbst oder eine Nachbardatei im SELBEN Ordner, damit
 *   eine geteilte HTML-Seite ihre Bilder laden kann. Eine Freigabe in der
 *   Wurzel bekommt keine Nachbarn, sonst waere sie ein Schluessel zum ganzen
 *   Bereich.
 *
 * Unbekannt → 404, zurueckgezogen oder abgelaufen → 410, gesperrt → 403.
 */
class OeffentlicheFreigabeController extends Controller
{
    public function __construct(private readonly Ablage $ablage) {}

    public function info(Request $request, string $token): JsonResponse
    {
        $link = $this->link($token);
        $name = basename($link->vault_path);
        $format = strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'html' ? 'html' : 'md';

        $kopf = [
            'name' => $name,
            'format' => $format,
            'sharedBy' => $link->user?->name,
            'expiresAt' => $link->expires_at?->toIso8601String(),
            'rohUrl' => $this->praefix().'/'.$link->token.'/roh',
        ];

        if ($this->gesperrt($request, $link)) {
            return response()->json($kopf + ['gesperrt' => true, 'content' => null]);
        }

        $absolut = $this->ablage->aufloesen($this->bereich($link), $link->vault_path);
        if (! is_file($absolut)) {
            throw new NotFoundHttpException;
        }

        $link->increment('view_count');

        return response()->json($kopf + [
            'gesperrt' => false,
            'content' => $format === 'md' ? (string) file_get_contents($absolut) : null,
        ]);
    }

    public function entsperren(Request $request, string $token): JsonResponse
    {
        $link = $this->link($token);
        $daten = $request->validate(['password' => 'required|string']);

        if (! $link->requiresPassword() || ! Hash::check($daten['password'], (string) $link->password_hash)) {
            return response()->json(['errors' => ['password' => ['Falsches Passwort.']], 'message' => 'Falsches Passwort.'], 422);
        }

        $request->session()->put('vault_share_unlocked:'.$link->id, true);

        return response()->json(['ok' => true]);
    }

    public function roh(Request $request, string $token, ?string $unterpfad = null): Response
    {
        $link = $this->link($token);

        if ($this->gesperrt($request, $link)) {
            throw new AccessDeniedHttpException('Passwort erforderlich.');
        }

        [$absolut, $relativ] = $this->ziel($link, $unterpfad);
        $kopf = Auslieferung::kopf($relativ, false);

        if (str_starts_with($kopf['Content-Type'], 'text/html')) {
            $html = $this->basisSetzen((string) file_get_contents($absolut), $link);

            // HTML ohne Zwischenspeicher, damit eine geaenderte Fassung sofort ankommt.
            return response($html, 200, ['Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0'] + $kopf);
        }

        return response()->file($absolut, $kopf);
    }

    private function link(string $token): VaultShare
    {
        $link = VaultShare::query()->where('token', $token)->first() ?? throw new NotFoundHttpException;

        if (! $link->isActive()) {
            throw new GoneHttpException($link->isRevoked() ? 'Dieser Link wurde zurueckgezogen.' : 'Dieser Link ist abgelaufen.');
        }

        return $link;
    }

    private function gesperrt(Request $request, VaultShare $link): bool
    {
        return $link->requiresPassword() && ! $request->session()->get('vault_share_unlocked:'.$link->id);
    }

    private function bereich(VaultShare $link): Bereich
    {
        return $link->area_key ? Bereich::geteilt($link->area_key) : Bereich::person((int) $link->user_id);
    }

    /** @return array{0: string, 1: string} */
    private function ziel(VaultShare $link, ?string $unterpfad): array
    {
        $bereich = $this->bereich($link);

        if ($unterpfad === null || $unterpfad === '') {
            $absolut = $this->ablage->aufloesen($bereich, $link->vault_path);

            return is_file($absolut) ? [$absolut, $link->vault_path] : throw new NotFoundHttpException;
        }

        $ordner = dirname($link->vault_path);
        if ($ordner === '' || $ordner === '.') {
            throw new NotFoundHttpException;
        }

        $relativ = $ordner.'/'.ltrim($unterpfad, '/');

        try {
            $absolut = $this->ablage->aufloesen($bereich, $relativ);
            $ordnerAbsolut = $this->ablage->aufloesen($bereich, $ordner);
        } catch (AccessDeniedHttpException) {
            throw new NotFoundHttpException;
        }

        // Doppelt gesichert: Die Ablage haelt nur die Bereichsgrenze, hier zaehlt der Ordner.
        if (! is_file($absolut) || ! str_starts_with($absolut, rtrim($ordnerAbsolut, '/').'/')) {
            throw new NotFoundHttpException;
        }

        return [$absolut, $relativ];
    }

    /** Relative Adressen der Seite gegen die oeffentliche Schiene aufloesen. */
    private function basisSetzen(string $html, VaultShare $link): string
    {
        $ordner = dirname($link->vault_path);
        if ($ordner === '' || $ordner === '.') {
            return $html;
        }

        $basis = '<base href="'.$this->praefix().'/'.$link->token.'/roh/">';
        $neu = preg_replace('#<head\b[^>]*>#i', '$0'.$basis, $html, 1, $anzahl);

        return $anzahl > 0 ? (string) $neu : $html;
    }

    private function praefix(): string
    {
        return '/'.trim((string) config('vault.routen.oeffentlich_praefix', 'vault/geteilt'), '/');
    }
}
