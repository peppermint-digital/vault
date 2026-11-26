<?php

namespace Peppermint\Vault\Mcp;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Vault;

/**
 * Eine Textdatei anlegen oder ueberschreiben.
 *
 * Mit `base_version` (aus `VaultLesen`) wird eine zwischenzeitliche Aenderung
 * erkannt und NICHT ueberschrieben — ein Agent soll nie still die Arbeit eines
 * Menschen ersetzen. Jede Aenderung landet mit Autor „assistant fuer …" in den
 * Versionen und ist damit zurueckholbar.
 */
abstract class VaultSchreiben extends VaultWerkzeug
{
    protected bool $schreibt = true;

    protected string $description = 'Schreibt eine Textdatei in den Vault (anlegen oder ersetzen). Mit base_version wird nicht ueber eine inzwischen geaenderte Fassung geschrieben.';

    protected function ausfuehren(Request $request, Vault $vault, Bereich $bereich, Authenticatable $person): Response
    {
        $pfad = trim((string) $request->get('path', ''), '/');
        $inhalt = (string) $request->get('content', '');
        $basis = $request->get('base_version');

        if ($pfad === '') {
            return Response::error('Bitte einen Pfad angeben.');
        }

        if (is_string($basis) && $basis !== '') {
            $aktuell = $vault->existiert($bereich, $pfad) ? md5($vault->lesen($bereich, $pfad)) : '';

            if ($aktuell !== $basis) {
                return Response::error('Die Datei wurde inzwischen geaendert. Bitte neu lesen und die Aenderung auf der aktuellen Fassung aufbauen.');
            }
        }

        $neu = ! $vault->existiert($bereich, $pfad);
        $vault->schreiben($bereich, $pfad, $inhalt, $this->autor($person));

        return Response::text(($neu ? '✅ Angelegt: ' : '✅ Gespeichert: ').$pfad.' (Fassung '.md5($inhalt).')');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('Pfad relativ zur Wurzel, z.B. "projekte/notiz.md"')->required(),
            'content' => $schema->string()->description('Der vollstaendige neue Inhalt')->required(),
            'base_version' => $schema->string()->description('Fassung aus dem Lesen; schuetzt vor dem Ueberschreiben fremder Aenderungen'),
        ];
    }
}
