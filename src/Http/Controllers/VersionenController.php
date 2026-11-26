<?php

namespace Peppermint\Vault\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Http\Bereichswahl;
use Peppermint\Vault\Models\VaultVersion;
use Peppermint\Vault\Vault;

class VersionenController extends Controller
{
    public function __construct(
        private readonly Vault $vault,
        private readonly Bereichswahl $wahl,
    ) {}

    public function liste(Request $request): JsonResponse
    {
        $request->validate(['path' => 'required|string']);
        $bereich = $this->wahl->aus($request);

        return response()->json([
            'versions' => $this->vault->versionen()->liste($bereich, (string) $request->query('path'))
                ->map(fn (VaultVersion $v): array => [
                    'id' => $v->id,
                    'author' => $v->author,
                    'size' => $v->size,
                    'binary' => $v->isBinary(),
                    'created_at' => $v->created_at?->toIso8601String(),
                ])->values(),
        ]);
    }

    public function inhalt(Request $request, int $version): JsonResponse
    {
        $stand = VaultVersion::query()->findOrFail($version);
        $this->wahl->pruefen($request, Bereich::aus($stand->scope));

        return response()->json([
            'id' => $stand->id,
            'path' => $stand->path,
            'binary' => $stand->isBinary(),
            'content' => $stand->isBinary() ? null : $stand->content,
        ]);
    }

    public function wiederherstellen(Request $request, int $version): JsonResponse
    {
        $stand = VaultVersion::query()->findOrFail($version);
        $this->wahl->pruefen($request, Bereich::aus($stand->scope), schreiben: true);

        $pfad = $this->vault->versionWiederherstellen($stand->id, (string) ($request->user()->name ?? 'restore'));

        return response()->json(['ok' => true, 'path' => $pfad]);
    }
}
