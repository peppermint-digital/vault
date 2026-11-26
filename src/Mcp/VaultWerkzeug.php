<?php

namespace Peppermint\Vault\Mcp;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Contracts\Vaultrechte;
use Peppermint\Vault\Vault;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Grundlage der bereichsneutralen MCP-Werkzeuge (Plan §3.1: lesen, suchen,
 * schreiben, auflisten). Name, Beschreibung und Bereich setzt das Produkt:
 *
 *     class LeseFirmenVault extends VaultLesen
 *     {
 *         protected string $name = 'read-firmen-vault';
 *         protected function bereich(Request $r, Authenticatable $p): Bereich { return Bereich::global(); }
 *     }
 *
 * Ohne handelnde Person gibt es KEINE Antwort (Plan, Falle 8). In AI Brain
 * fiel ein Aufruf ohne Person auf den allgemeinen Bereich zurueck; ein Paket,
 * das Produkte mit Firmendaten bedient, darf das nicht.
 *
 * Rechte laufen ueber {@see Vaultrechte} wie in der Oberflaeche. `laravel/mcp`
 * steht nur in `suggest`: Ohne das Paket werden diese Klassen nie geladen.
 */
abstract class VaultWerkzeug extends Tool
{
    /** Welcher Bereich fuer diese Person gemeint ist. */
    abstract protected function bereich(Request $request, Authenticatable $person): Bereich;

    /** Schreibt das Werkzeug? Dann prueft die Basis das Schreibrecht. */
    protected bool $schreibt = false;

    abstract protected function ausfuehren(Request $request, Vault $vault, Bereich $bereich, Authenticatable $person): Response;

    public function handle(Request $request, Vault $vault, Vaultrechte $rechte): Response
    {
        $person = $request->user();

        if ($person === null) {
            return Response::error('Kein Zugriff: Dieses Werkzeug arbeitet nur fuer eine angemeldete Person.');
        }

        $bereich = $this->bereich($request, $person);

        if (! $rechte->darfLesen($person, $bereich) || ($this->schreibt && ! $rechte->darfSchreiben($person, $bereich))) {
            return Response::error($this->schreibt ? 'Keine Schreibrechte in diesem Bereich.' : 'Kein Zugriff auf diesen Bereich.');
        }

        try {
            return $this->ausfuehren($request, $vault, $bereich, $person);
        } catch (HttpExceptionInterface $e) {
            return Response::error(match ($e->getStatusCode()) {
                404 => 'Nicht gefunden.',
                403 => 'Ungueltiger Pfad.',
                default => $e->getMessage() ?: 'Das ging nicht.',
            });
        }
    }

    /** Autorname fuer die Versionsliste: Werkzeug-Aenderungen sind als solche erkennbar. */
    protected function autor(Authenticatable $person): string
    {
        $name = $person->name ?? null;

        return 'assistant'.(is_string($name) && $name !== '' ? ' fuer '.$name : '');
    }
}
