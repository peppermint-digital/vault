<?php

namespace Peppermint\Vault\Http;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Contracts\Bereichsnamen;
use Peppermint\Vault\Contracts\Vaultrechte;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `?scope=` → Bereich, mit Rechtepruefung (AI Brain `VaultController::scope`).
 *
 * Welche Namen es gibt, sagt {@see Bereichsnamen}; was erlaubt ist,
 * {@see Vaultrechte}. Wer nicht lesen darf, bekommt 404 — ein fremder Bereich
 * soll nicht einmal als vorhanden erkennbar sein. Ein unbekannter Name ebenso.
 * Wer lesen, aber nicht schreiben darf, bekommt 403.
 */
class Bereichswahl
{
    public function __construct(
        private readonly Vaultrechte $rechte,
        private readonly Bereichsnamen $namen,
    ) {}

    public function aus(Request $request, bool $schreiben = false): Bereich
    {
        $user = $this->person($request);
        $name = (string) ($request->query('scope') ?? $request->input('scope', ''));

        $bereich = $this->namen->aus($name, $user) ?? throw new NotFoundHttpException;

        $this->pruefen($request, $bereich, $schreiben);

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
        return $this->namen->name($bereich) ?? '';
    }

    /** @return array{slug: string, label: string, persoenlich: bool, darfSchreiben: bool} */
    public function beschreibung(Request $request, Bereich $bereich): array
    {
        return [
            'slug' => $this->slug($bereich),
            'label' => $this->namen->bezeichnung($bereich),
            'persoenlich' => $bereich->istPersoenlich(),
            'darfSchreiben' => $this->rechte->darfSchreiben($this->person($request), $bereich),
        ];
    }

    private function person(Request $request): Authenticatable
    {
        return $request->user() ?? throw new AccessDeniedHttpException;
    }
}
