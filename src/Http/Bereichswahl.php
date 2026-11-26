<?php

namespace Peppermint\Vault\Http;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Contracts\Vaultrechte;
use Peppermint\Vault\Models\VaultSpace;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `?scope=` → Bereich, mit Rechtepruefung (AI Brain `VaultController::scope`).
 *
 * - leer oder `private` → der persoenliche Bereich der anfragenden Person;
 * - sonst der Slug eines Raums; der globale Raum steht fuer den globalen Bereich.
 *
 * Wer nicht lesen darf, bekommt 404 — ein fremder Raum soll nicht einmal als
 * vorhanden erkennbar sein. Wer lesen, aber nicht schreiben darf, bekommt 403.
 */
class Bereichswahl
{
    public function __construct(private readonly Vaultrechte $rechte) {}

    public function aus(Request $request, bool $schreiben = false): Bereich
    {
        $user = $this->person($request);
        $slug = trim((string) ($request->query('scope') ?? $request->input('scope', '')));

        $bereich = $slug === '' || $slug === 'private'
            ? Bereich::person((int) $user->getAuthIdentifier())
            : $this->raum($slug);

        if (! $this->rechte->darfLesen($user, $bereich)) {
            throw new NotFoundHttpException;
        }

        if ($schreiben && ! $this->rechte->darfSchreiben($user, $bereich)) {
            throw new AccessDeniedHttpException('Keine Schreibrechte in diesem Bereich.');
        }

        return $bereich;
    }

    /** Fuer Eintraege, die ihren Bereich selbst tragen (Version, Papierkorb). */
    public function pruefen(Request $request, Bereich $bereich, bool $schreiben = false): void
    {
        $user = $this->person($request);

        if (! $this->rechte->darfLesen($user, $bereich)) {
            throw new NotFoundHttpException;
        }

        if ($schreiben && ! $this->rechte->darfSchreiben($user, $bereich)) {
            throw new AccessDeniedHttpException('Keine Schreibrechte in diesem Bereich.');
        }
    }

    /** Der `scope`-Wert, unter dem ein Bereich in Anfragen heisst. */
    public function slug(Bereich $bereich): string
    {
        if ($bereich->istPersoenlich()) {
            return 'private';
        }

        $raum = $bereich->istGleich(Bereich::global())
            ? VaultSpace::query()->where('is_global', true)->first()
            : VaultSpace::query()->where('is_global', false)->get()->first(fn (VaultSpace $r) => $r->bereich()->istGleich($bereich));

        return $raum?->slug ?? '';
    }

    /** @return array{slug: string, label: string, persoenlich: bool, darfSchreiben: bool} */
    public function beschreibung(Request $request, Bereich $bereich): array
    {
        $raum = null;
        if (! $bereich->istPersoenlich()) {
            $slug = $this->slug($bereich);
            $raum = VaultSpace::query()->where('slug', $slug)->first();
        }

        return [
            'slug' => $this->slug($bereich),
            'label' => $raum?->name ?? 'Persoenlich',
            'persoenlich' => $bereich->istPersoenlich(),
            'darfSchreiben' => $this->rechte->darfSchreiben($this->person($request), $bereich),
        ];
    }

    private function raum(string $slug): Bereich
    {
        $raum = VaultSpace::query()->where('slug', $slug)->first();

        if ($raum === null) {
            throw new NotFoundHttpException;
        }

        return $raum->bereich();
    }

    private function person(Request $request): Authenticatable
    {
        return $request->user() ?? throw new AccessDeniedHttpException;
    }
}
