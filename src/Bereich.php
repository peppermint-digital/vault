<?php

namespace Peppermint\Vault;

use InvalidArgumentException;

/**
 * Ein Bereich des Vaults: persoenlich (`user:{id}`) oder geteilt (`area:{key}`).
 *
 * Der Schluessel ist derselbe, den AI Brain seit #2905 in `vault_versions.scope`
 * und `vault_trash.scope` schreibt. Er darf sich nicht aendern, sonst findet
 * niemand mehr die Versionen und den Papierkorb des Bestands.
 *
 * `key` eines geteilten Bereichs ist ein Ordner unterhalb der Disk
 * (`_shared`, `firma`, `_spaces/<slug>`). Er stammt aus der Konfiguration oder
 * einem validierten Raum-Slug, nie aus rohem Benutzer-Input.
 */
final class Bereich
{
    private function __construct(
        public readonly string $typ,
        public readonly string $schluessel,
    ) {}

    public static function person(int $userId): self
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('Ein persoenlicher Bereich braucht eine Benutzer-ID.');
        }

        return new self('user', (string) $userId);
    }

    public static function geteilt(string $key): self
    {
        $key = trim($key, '/');

        if ($key === '' || str_contains($key, "\0") || in_array('..', explode('/', $key), true)) {
            throw new InvalidArgumentException('Ungueltiger Bereich.');
        }

        // Eine fuehrende Ziffer gehoert den persoenlichen Bereichen.
        if (ctype_digit($key[0])) {
            throw new InvalidArgumentException('Ein geteilter Bereich darf nicht mit einer Ziffer beginnen.');
        }

        return new self('area', $key);
    }

    /** Der globale Bereich aus der Konfiguration (AI Brain `_shared`, Manager `firma`). */
    public static function global(): self
    {
        return self::geteilt((string) config('vault.bereiche.global', '_shared'));
    }

    public static function raum(string $slug): self
    {
        return self::geteilt(trim((string) config('vault.bereiche.raeume', '_spaces'), '/').'/'.$slug);
    }

    /**
     * Eine extern angebundene Quelle (AI Brain #6221), Schluessel `source:{slug}`
     * wie dort. Fremder Speicher: keine Versionen, kein Papierkorb, kein Index.
     */
    public static function quelle(string $slug): self
    {
        if ($slug === '' || ! preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug)) {
            throw new InvalidArgumentException('Ungueltige Quelle.');
        }

        return new self('source', $slug);
    }

    public function istQuelle(): bool
    {
        return $this->typ === 'source';
    }

    /** Aus `user:{id}`, `area:{key}` oder `source:{slug}`. */
    public static function aus(string $scope): self
    {
        [$typ, $key] = array_pad(explode(':', $scope, 2), 2, '');

        return match ($typ) {
            'user' => ctype_digit($key) ? self::person((int) $key) : throw new InvalidArgumentException("Unbekannter Bereich: {$scope}"),
            'area' => self::geteilt($key),
            'source' => self::quelle($key),
            default => throw new InvalidArgumentException("Unbekannter Bereich: {$scope}"),
        };
    }

    public function istPersoenlich(): bool
    {
        return $this->typ === 'user';
    }

    public function benutzerId(): ?int
    {
        return $this->istPersoenlich() ? (int) $this->schluessel : null;
    }

    /** Ordner unterhalb der Disk. */
    public function ordner(): string
    {
        return $this->schluessel;
    }

    /** Der Schluessel fuer `vault_versions.scope` und `vault_trash.scope`. */
    public function scope(): string
    {
        return $this->typ.':'.$this->schluessel;
    }

    public function istGleich(self $anderer): bool
    {
        return $this->scope() === $anderer->scope();
    }

    public function __toString(): string
    {
        return $this->scope();
    }
}
