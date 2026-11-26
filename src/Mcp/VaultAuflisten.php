<?php

namespace Peppermint\Vault\Mcp;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Vault;

/** Den Baum eines Bereichs zeigen, optional ab einem Unterordner (AI Brain `ListNotesTool`). */
#[IsReadOnly]
abstract class VaultAuflisten extends VaultWerkzeug
{
    protected string $description = 'Zeigt den Verzeichnisbaum des Vaults, optional ab einem Unterordner.';

    protected function ausfuehren(Request $request, Vault $vault, Bereich $bereich, Authenticatable $person): Response
    {
        $ordner = trim((string) $request->get('path', ''), '/');
        $baum = $vault->baum($bereich);

        if ($ordner !== '') {
            $baum = $this->teilbaum($baum, $ordner);
            if ($baum === null) {
                return Response::error("Ordner nicht gefunden: {$ordner}");
            }
        }

        if ($baum === []) {
            return Response::text('📂 Hier liegt nichts.');
        }

        return Response::text('📂 '.($ordner !== '' ? $ordner : 'Vault')."\n\n".$this->zeilen($baum, 0));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['path' => $schema->string()->description('Optionaler Unterordner, z.B. "projekte"')->default('')];
    }

    /** @param  array<int, array{name: string, path: string, type: string, children?: array}>  $baum */
    private function zeilen(array $baum, int $tiefe): string
    {
        $raus = '';
        foreach ($baum as $e) {
            $raus .= str_repeat('  ', $tiefe).($e['type'] === 'folder' ? "📁 {$e['name']}/" : "📄 {$e['name']}")."\n";
            if ($e['type'] === 'folder' && ! empty($e['children'])) {
                $raus .= $this->zeilen($e['children'], $tiefe + 1);
            }
        }

        return $raus;
    }

    /**
     * @param  array<int, array{name: string, path: string, type: string, children?: array}>  $baum
     * @return array<int, array<string, mixed>>|null
     */
    private function teilbaum(array $baum, string $pfad): ?array
    {
        foreach ($baum as $e) {
            if ($e['type'] !== 'folder') {
                continue;
            }
            if ($e['path'] === $pfad) {
                return $e['children'] ?? [];
            }
            if (str_starts_with($pfad, $e['path'].'/') && ($treffer = $this->teilbaum($e['children'] ?? [], $pfad)) !== null) {
                return $treffer;
            }
        }

        return null;
    }
}
