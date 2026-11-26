<?php

namespace Peppermint\Vault\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Contracts\Einhaengung;
use Peppermint\Vault\Contracts\Vaultrechte;

/**
 * Eine extern angebundene Quelle (AI Brain `VaultSource`, #6221) — heute eine
 * SMB-Freigabe, auf dem Host eingehaengt. Eigene Regeln: keine Versionen,
 * kein Papierkorb, kein Index. Es gilt, was die Freigabe vorgibt.
 *
 * Rechte stehen in {@see Vaultrechte}, nicht hier
 * (in AI Brain lagen sie doppelt). Dort gilt: Eine Quelle sehen nur ihre
 * Mitglieder — auch Verwalter nicht automatisch, wie zuvor in Nextcloud.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property ?string $host
 * @property ?string $share_name
 * @property string $mount_path
 * @property bool $is_active
 */
class VaultSource extends Model
{
    use Tabellenname;

    protected $fillable = ['name', 'slug', 'host', 'share_name', 'mount_path', 'is_active'];

    protected static function tabellenSchluessel(): string
    {
        return 'sources';
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsToMany<Model, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(
            (string) config('vault.user_model'),
            (string) config('vault.tables.source_user', 'vault_source_user'),
            'vault_source_id',
            'user_id',
        )->withPivot('role')->withTimestamps();
    }

    public function bereich(): Bereich
    {
        return Bereich::quelle($this->slug);
    }

    public function istEingehaengt(): bool
    {
        return app(Einhaengung::class)->istEingehaengt($this->mount_path);
    }

    /** Erreichbar heisst: eingeschaltet UND eingehaengt. */
    public function istErreichbar(): bool
    {
        return $this->is_active && $this->istEingehaengt();
    }

    public function roleFor(Authenticatable $user): ?string
    {
        return $this->members()->whereKey($user->getAuthIdentifier())->first()?->pivot?->role;
    }

    /**
     * @param  Builder<VaultSource>  $query
     * @return Builder<VaultSource>
     */
    public function scopeVisibleTo(Builder $query, Authenticatable $user): Builder
    {
        return $query->where('is_active', true)->whereHas('members', fn (Builder $m) => $m->whereKey($user->getAuthIdentifier()));
    }
}
