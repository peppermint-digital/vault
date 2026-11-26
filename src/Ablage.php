<?php

namespace Peppermint\Vault;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Wo ein Bereich auf der Platte liegt — und der EINE Weg von einem relativen
 * Pfad zu einem absoluten.
 *
 * `aufloesen()` ist `VaultService::resolveWithinBase()` aus AI Brain
 * (Stand 7b8eddee), unveraendert in der Logik: Null-Bytes, absolute Pfade,
 * `..`-Segmente, Ausbruch per realpath und symbolische Links werden
 * abgewiesen. Jeder lesende und schreibende Weg des Pakets laeuft hier
 * durch. Eine zweite Implementierung waere eine zweite Gelegenheit, es falsch
 * zu machen.
 */
class Ablage
{
    /** Wurzel der Disk, ohne Schraegstrich am Ende. */
    public function wurzel(): string
    {
        return rtrim(Storage::disk((string) config('vault.disk', 'vault'))->path(''), '/\\');
    }

    /** Basisverzeichnis eines Bereichs (wird bei Bedarf angelegt). */
    public function basis(Bereich $bereich): string
    {
        return $this->wurzel().DIRECTORY_SEPARATOR.$bereich->ordner();
    }

    public function aufloesen(Bereich $bereich, string $relativePath = ''): string
    {
        return $this->innerhalb($this->basis($bereich), $relativePath);
    }

    /**
     * Pfadsicherheit fuer eine beliebige Basis (auch eingehaengte Quellen).
     *
     * @return string Der absolute, gepruefte Pfad
     */
    public function innerhalb(string $basePath, string $relativePath = ''): string
    {
        if (! File::isDirectory($basePath)) {
            File::makeDirectory($basePath, 0755, true);
        }

        if ($relativePath === '' || $relativePath === '/') {
            return $basePath;
        }

        if (str_contains($relativePath, "\0")) {
            throw new AccessDeniedHttpException('Invalid path.');
        }

        if (str_starts_with($relativePath, '/')) {
            throw new AccessDeniedHttpException('Invalid path.');
        }

        foreach (explode('/', $relativePath) as $segment) {
            if ($segment === '..') {
                throw new AccessDeniedHttpException('Invalid path.');
            }
        }

        $fullPath = $basePath.DIRECTORY_SEPARATOR.$relativePath;

        if (file_exists($fullPath)) {
            $realPath = realpath($fullPath);

            if ($realPath === false || ! $this->liegtIn($realPath, $basePath)) {
                throw new AccessDeniedHttpException('Invalid path.');
            }

            if (is_link($fullPath)) {
                throw new AccessDeniedHttpException('Invalid path.');
            }

            return $realPath;
        }

        // Neue Datei: Der vorhandene Elternordner muss im Bereich liegen.
        $parentDir = dirname($fullPath);
        if (file_exists($parentDir)) {
            $realParent = realpath($parentDir);
            if ($realParent === false || ! $this->liegtIn($realParent, $basePath)) {
                throw new AccessDeniedHttpException('Invalid path.');
            }
        }

        return $fullPath;
    }

    /**
     * Liegt der aufgeloeste Pfad in der Basis oder ist er die Basis selbst?
     *
     * Abweichung von AI Brain: Dort hiess die Pruefung nur
     * `str_starts_with($real, realpath($basis))`. Damit lag `/vaults/10/x`
     * scheinbar in `/vaults/1` — erreichbar ueber einen verlinkten
     * Unterordner. Hier zaehlt der Trenner mit.
     */
    private function liegtIn(string $realPath, string $basePath): bool
    {
        $basis = realpath($basePath);

        if ($basis === false) {
            return false;
        }

        return $realPath === $basis || str_starts_with($realPath, rtrim($basis, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }

    /** Absoluter Pfad → relativ zur Basis, mit `/` als Trenner. */
    public function relativ(string $basePath, string $absolutePath): string
    {
        $rel = ltrim(substr($absolutePath, strlen($basePath)), DIRECTORY_SEPARATOR);

        return str_replace('\\', '/', $rel);
    }

    public function versionsPfad(): string
    {
        return rtrim((string) (config('vault.versionen.pfad') ?: storage_path('app/vault-versions')), '/');
    }

    public function papierkorbPfad(): string
    {
        return rtrim((string) (config('vault.papierkorb.pfad') ?: storage_path('app/vault-trash')), '/');
    }
}
