<?php

namespace Peppermint\Vault\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Peppermint\Vault\Bereich;

/**
 * Die feste ID einer Vault-Datei (F42).
 *
 * Pfade aendern sich — beim Umbenennen, Verschieben, Wiederherstellen. Eine
 * Verknuepfung mit einer Aufgabe darf daran nicht reissen. Die ID bleibt,
 * der Pfad in dieser Zeile wandert mit.
 *
 * Geloeschte Dateien behalten ihre Zeile (`deleted_at`, `trash_id`), damit
 * eine Wiederherstellung dieselbe ID und damit dieselben Verknuepfungen
 * zurueckbringt.
 *
 * @property string $id
 * @property string $scope
 * @property string $path
 * @property ?string $checksum
 * @property ?int $size
 * @property ?string $art
 * @property ?int $trash_id
 */
class VaultFile extends Model
{
    use HasUlids;
    use Tabellenname;

    protected $fillable = ['scope', 'path', 'checksum', 'size', 'art', 'deleted_at', 'trash_id'];

    protected static function tabellenSchluessel(): string
    {
        return 'files';
    }

    protected function casts(): array
    {
        return ['size' => 'integer', 'deleted_at' => 'datetime'];
    }

    public function bereich(): Bereich
    {
        return Bereich::aus($this->scope);
    }

    public function istGeloescht(): bool
    {
        return $this->deleted_at !== null;
    }

    /** @return HasMany<VaultLink, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(VaultLink::class, 'file_id');
    }
}
