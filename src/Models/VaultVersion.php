<?php

namespace Peppermint\Vault\Models;

use Illuminate\Database\Eloquent\Model;
use Peppermint\Vault\Ablage;

/**
 * Eine gespeicherte Version einer Vault-Datei (AI Brain #2905, Binaer #6219).
 *
 * `scope` ist der Bereichsschluessel (`user:{id}` / `area:{key}`), `content`
 * der volle Text zum Zeitpunkt des Schreibens. Binaerversionen tragen
 * `storage_path` und eine Pruefsumme; `content` bleibt dort leer.
 *
 * @property int $id
 * @property string $scope
 * @property string $path
 * @property string $content
 * @property ?string $storage_path
 * @property ?string $checksum
 * @property ?string $author
 * @property int $size
 */
class VaultVersion extends Model
{
    use Tabellenname;

    public const UPDATED_AT = null;

    /** Absoluter Rueckfall, falls die Konfiguration fehlt. */
    public const MAX_PER_FILE = 30;

    protected $fillable = [
        'scope',
        'path',
        'content',
        'storage_path',
        'checksum',
        'author',
        'size',
        'created_at',
    ];

    protected static function tabellenSchluessel(): string
    {
        return 'versions';
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'size' => 'integer',
        ];
    }

    public static function maxPerFile(): int
    {
        return max(1, (int) config('vault.versionen.max_je_datei', self::MAX_PER_FILE));
    }

    public static function maxBinaryPerFile(): int
    {
        return max(1, (int) config('vault.versionen.max_binaer_je_datei', 3));
    }

    /** `storage_path` ist das einzige Unterscheidungsmerkmal; alte Zeilen bleiben Text. */
    public function isBinary(): bool
    {
        return $this->storage_path !== null && $this->storage_path !== '';
    }

    public function absoluteStoragePath(): ?string
    {
        if (! $this->isBinary()) {
            return null;
        }

        return app(Ablage::class)->versionsPfad().'/'.ltrim((string) $this->storage_path, '/');
    }
}
