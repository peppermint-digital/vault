<?php

namespace Peppermint\Vault\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Peppermint\Vault\Ablage;

/**
 * Ein Eintrag im Papierkorb (AI Brain #6223). Die Zeile ist nur die
 * Buchhaltung; die Daten liegen unter dem Papierkorb-Pfad, ausserhalb der Disk.
 *
 * @property int $id
 * @property string $scope
 * @property string $original_path
 * @property string $type
 * @property string $storage_path
 * @property ?int $size
 * @property ?int $deleted_by
 */
class VaultTrash extends Model
{
    use Tabellenname;

    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected $fillable = [
        'scope',
        'original_path',
        'type',
        'storage_path',
        'size',
        'deleted_by',
        'deleted_at',
    ];

    protected static function tabellenSchluessel(): string
    {
        return 'trash';
    }

    protected function casts(): array
    {
        return ['deleted_at' => 'datetime', 'size' => 'integer'];
    }

    /** @return BelongsTo<Model, $this> */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo((string) config('vault.user_model'), 'deleted_by');
    }

    /** Nach wie vielen Tagen selbst aufgeraeumt wird. 0 heisst: gar nicht. */
    public static function aufbewahrungstage(): int
    {
        return max(0, (int) config('vault.papierkorb.tage', 30));
    }

    public function absoluterPfad(): string
    {
        return app(Ablage::class)->papierkorbPfad().'/'.ltrim($this->storage_path, '/');
    }

    public function istOrdner(): bool
    {
        return $this->type === 'folder';
    }

    public function name(): string
    {
        return basename($this->original_path);
    }
}
