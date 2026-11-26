<?php

namespace Peppermint\Vault\Anmerkungen;

use Illuminate\Support\Str;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Vault;
use RuntimeException;

/**
 * Stores EPUB annotations in a JSON sidecar next to the book.
 *
 * EPUB has no page geometry and no widely-supported in-file annotation standard
 * (EPUB-CFI support across readers is poor), so instead of mutating the .epub we
 * keep a reader-independent sidecar at "<book>.epub.annot.json". Each annotation
 * is an anchored quote plus an optional note — enough to find the passage again
 * and to surface it back through the Avatar.
 *
 * Aus AI Brain `EpubAnnotationService` (Stand 118ee2b2), mit {@see Bereich}
 * statt VaultScope. Die Seitendatei laeuft ueber den Text-Weg des Vaults und
 * bekommt damit Versionen und eine Datei-ID wie jede andere Datei.
 */
class EpubAnmerkungen
{
    public function __construct(private readonly Vault $vault) {}

    public const SIDECAR_SUFFIX = '.annot.json';

    /**
     * Append an annotation (quote + optional note) to the EPUB's sidecar.
     *
     * @return array{id:string,quote:string,note:?string,color:string,chapter:?string}
     */
    public function add(Bereich $scope, string $path, string $quote, ?string $note = null, string $color = 'yellow', ?string $chapter = null, ?string $author = null): array
    {
        $this->assertEpub($path);

        $store = $this->load($scope, $path);

        $annotation = [
            'id' => (string) Str::uuid(),
            'quote' => $quote,
            'note' => $note ?: null,
            'color' => $color,
            'chapter' => $chapter ?: null,
            'author' => $author ?: 'AI Brain',
            'created_at' => now()->toIso8601String(),
        ];

        $store['annotations'][] = $annotation;
        $this->save($scope, $path, $store);

        return $annotation;
    }

    /**
     * Read all annotations for an EPUB out of its sidecar.
     *
     * @return array{count:int,annotations:array<int,array<string,mixed>>}
     */
    public function extract(Bereich $scope, string $path): array
    {
        $this->assertEpub($path);
        $store = $this->load($scope, $path);

        return [
            'count' => count($store['annotations']),
            'annotations' => $store['annotations'],
        ];
    }

    /**
     * Update an existing annotation's note and/or colour by its id — used to
     * attach a note to a mark after the fact. A null note clears it.
     *
     * @return array{updated:int}
     */
    public function update(Bereich $scope, string $path, string $id, ?string $note = null, ?string $color = null): array
    {
        $this->assertEpub($path);
        $store = $this->load($scope, $path);

        $updated = 0;
        foreach ($store['annotations'] as &$annotation) {
            if (($annotation['id'] ?? null) === $id) {
                $annotation['note'] = $note ?: null;
                if ($color) {
                    $annotation['color'] = $color;
                }
                $updated = 1;
                break;
            }
        }
        unset($annotation);

        if ($updated > 0) {
            $this->save($scope, $path, $store);
        }

        return ['updated' => $updated];
    }

    /**
     * Remove an annotation from the sidecar by its id.
     *
     * @return array{removed:int,count:int}
     */
    public function remove(Bereich $scope, string $path, string $id): array
    {
        $this->assertEpub($path);
        $store = $this->load($scope, $path);

        $before = count($store['annotations']);
        $store['annotations'] = array_values(array_filter(
            $store['annotations'],
            fn (array $a): bool => ($a['id'] ?? null) !== $id,
        ));
        $removed = $before - count($store['annotations']);

        if ($removed > 0) {
            $this->save($scope, $path, $store);
        }

        return ['removed' => $removed, 'count' => count($store['annotations'])];
    }

    protected function assertEpub(string $path): void
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'epub') {
            throw new RuntimeException("Keine EPUB-Datei: {$path}");
        }
    }

    protected function sidecarPath(string $path): string
    {
        return $path.self::SIDECAR_SUFFIX;
    }

    /**
     * Load the sidecar, returning an empty store when it doesn't exist yet.
     *
     * @return array{version:int,file:string,annotations:array<int,array<string,mixed>>}
     */
    protected function load(Bereich $scope, string $path): array
    {
        try {
            $raw = $this->vault->lesen($scope, $this->sidecarPath($path));
        } catch (\Throwable) {
            return ['version' => 1, 'file' => basename($path), 'annotations' => []];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || ! isset($decoded['annotations']) || ! is_array($decoded['annotations'])) {
            return ['version' => 1, 'file' => basename($path), 'annotations' => []];
        }

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $store
     */
    protected function save(Bereich $scope, string $path, array $store): void
    {
        $this->vault->schreiben(
            $scope,
            $this->sidecarPath($path),
            json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
}
