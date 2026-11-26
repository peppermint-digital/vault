<?php

namespace Peppermint\Vault\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Http\Bereichswahl;
use Peppermint\Vault\Models\VaultTrash;
use Peppermint\Vault\Papierkorb;

/**
 * Papierkorb eines Bereichs. Ansehen darf, wer lesen darf; zurueckholen und
 * endgueltig entfernen nur, wer schreiben darf.
 */
class PapierkorbController extends Controller
{
    public function __construct(
        private readonly Papierkorb $papierkorb,
        private readonly Bereichswahl $wahl,
    ) {}

    public function liste(Request $request): JsonResponse
    {
        $bereich = $this->wahl->aus($request);

        return response()->json([
            'eintraege' => $this->papierkorb->liste($bereich)->map(fn (VaultTrash $e): array => [
                'id' => $e->id,
                'original_path' => $e->original_path,
                'type' => $e->type,
                'size' => $e->size,
                'deleted_at' => $e->deleted_at?->toIso8601String(),
                'deleted_by' => $e->deletedBy ? ['id' => $e->deletedBy->getKey(), 'name' => $e->deletedBy->name] : null,
            ])->values(),
        ]);
    }

    public function wiederherstellen(Request $request, int $eintrag): JsonResponse
    {
        $zeile = VaultTrash::query()->findOrFail($eintrag);
        $this->wahl->pruefen($request, Bereich::aus($zeile->scope), schreiben: true);

        return response()->json(['ok' => true, 'path' => $this->papierkorb->zurueckholen($zeile)]);
    }

    public function entfernen(Request $request, int $eintrag): JsonResponse
    {
        $zeile = VaultTrash::query()->findOrFail($eintrag);
        $this->wahl->pruefen($request, Bereich::aus($zeile->scope), schreiben: true);

        $this->papierkorb->leeren($zeile);

        return response()->json(['ok' => true]);
    }
}
