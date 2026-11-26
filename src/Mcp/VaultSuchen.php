<?php

namespace Peppermint\Vault\Mcp;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Vault;

/** Volltextsuche ueber Dateiname und Inhalt (md, html). */
#[IsReadOnly]
abstract class VaultSuchen extends VaultWerkzeug
{
    protected string $description = 'Durchsucht Dateinamen und Inhalt (Markdown und HTML) eines Vault-Bereichs.';

    protected function ausfuehren(Request $request, Vault $vault, Bereich $bereich, Authenticatable $person): Response
    {
        $suche = trim((string) $request->get('query', ''));

        if ($suche === '') {
            return Response::error('Bitte einen Suchbegriff angeben.');
        }

        $treffer = $vault->suchen($bereich, $suche, max(1, min(50, (int) $request->get('limit', 10))));

        if ($treffer === []) {
            return Response::text("Nichts gefunden zu „{$suche}".'".');
        }

        return Response::text(implode("\n", array_map(
            fn (array $t): string => "- **{$t['path']}**".($t['excerpt'] !== '' ? " — {$t['excerpt']}" : ''),
            $treffer,
        )));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Suchbegriff')->required(),
            'limit' => $schema->integer()->description('Hoechstzahl der Treffer (1–50)')->default(10),
        ];
    }
}
