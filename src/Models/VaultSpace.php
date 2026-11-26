<?php

namespace Peppermint\Vault\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Contracts\Vaultrechte;

/**
 * Ein geteilter Bereich (AI Brain #278): genau eine Zeile ist `is_global`
 * (alle lesen), alle anderen sind Raeume mit Mitgliederliste.
 *
 * Die Rechte stehen NICHT hier, sondern im Vertrag {@see Vaultrechte}. In AI
 * Brain lagen sie doppelt — hier und in `VaultSource` — und liefen
 * auseinander.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property ?string $description
 * @property bool $is_global
 */
class VaultSpace extends Model
{
    use Tabellenname;

    public const ROLLE_BEARBEITEN = 'editor';

    public const ROLLE_LESEN = 'viewer';

    protected $fillable = ['name', 'slug', 'description', 'is_global'];

    protected static function tabellenSchluessel(): string
    {
        return 'spaces';
    }

    protected function casts(): array
    {
        return ['is_global' => 'boolean'];
    }

    /** @return BelongsToMany<Model, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(
            (string) config('vault.user_model'),
            (string) config('vault.tables.space_user', 'vault_space_user'),
            'vault_space_id',
            'user_id',
        )->withPivot('role')->withTimestamps();
    }

    public function bereich(): Bereich
    {
        return $this->is_global ? Bereich::global() : Bereich::raum($this->slug);
    }

    /** Ordner unterhalb der Disk — wie `areaKey()` in AI Brain. */
    public function areaKey(): string
    {
        return $this->bereich()->ordner();
    }

    public function isMember(Authenticatable $user): bool
    {
        return $this->members()->whereKey($user->getAuthIdentifier())->exists();
    }

    public function roleFor(Authenticatable $user): ?string
    {
        $member = $this->members()->whereKey($user->getAuthIdentifier())->first();

        return $member?->pivot?->role;
    }

    public function canView(Authenticatable $user): bool
    {
        return app(Vaultrechte::class)->darfLesen($user, $this->bereich());
    }

    public function canEdit(Authenticatable $user): bool
    {
        return app(Vaultrechte::class)->darfSchreiben($user, $this->bereich());
    }

    /**
     * Raeume, die die Person sehen darf: der globale, ihre eigenen — Verwalter alle.
     *
     * @param  Builder<VaultSpace>  $query
     * @return Builder<VaultSpace>
     */
    public function scopeVisibleTo(Builder $query, Authenticatable $user): Builder
    {
        if (app(Vaultrechte::class)->istVerwalter($user)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user): void {
            $q->where('is_global', true)
                ->orWhereHas('members', fn (Builder $m) => $m->whereKey($user->getAuthIdentifier()));
        });
    }
}
