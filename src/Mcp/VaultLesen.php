<?php

namespace Peppermint\Vault\Mcp;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Vault;

/** Eine Datei lesen. Gibt die Fassung mit, damit `VaultSchreiben` Konflikte erkennt. */
#[IsReadOnly]
abstract class VaultLesen extends VaultWerkzeug
{
    protected string $description = 'Liest eine Datei aus dem Vault. Der Pfad ist relativ zur Wurzel des Bereichs.';

    protected function ausfuehren(Request $request, Vault $vault, Bereich $bereich, Authenticatable $person): Response
    {
        $pfad = (string) $request->get('path', '');

        if (! Vault::istTextdatei($pfad)) {
            return Response::error('Nur Textdateien lassen sich lesen (md, html, txt, json, csv …).');
        }

        $inhalt = $vault->lesen($bereich, $pfad);

        return Response::text("📄 **{$pfad}** (Fassung ".md5($inhalt).")\n\n".$inhalt);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['path' => $schema->string()->description('Pfad relativ zur Wurzel, z.B. "projekte/angebot.md"')->required()];
    }
}
