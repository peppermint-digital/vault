<?php

namespace Peppermint\Vault\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Eine Datei haengt an einem Objekt des Produkts (Aufgabe, Projekt, Kunde …).
 *
 * Viele-zu-viele: Eine Notiz kann an mehreren Objekten haengen, ein Objekt
 * viele Dateien haben. `linkable_type` traegt das Morph-Kuerzel des Produkts
 * (`getMorphClass()`), nie einen Klassennamen raten.
 *
 * @property int $id
 * @property string $file_id
 * @property string $linkable_type
 * @property string $linkable_id
 * @property string $rolle
 * @property bool $angeheftet
 * @property ?int $user_id
 */
class VaultLink extends Model
{
    use Tabellenname;

    public const ROLLE_NOTIZ = 'notiz';

    public const ROLLE_ANHANG = 'anhang';

    protected $fillable = ['file_id', 'linkable_type', 'linkable_id', 'rolle', 'angeheftet', 'user_id'];

    protected static function tabellenSchluessel(): string
    {
        return 'links';
    }

    protected function casts(): array
    {
        return ['angeheftet' => 'boolean'];
    }

    /** @return BelongsTo<VaultFile, $this> */
    public function datei(): BelongsTo
    {
        return $this->belongsTo(VaultFile::class, 'file_id');
    }

    /** @return MorphTo<Model, $this> */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}
