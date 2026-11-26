<?php

namespace Peppermint\Vault\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein Freigabelink auf eine Vault-Datei (AI Brain `NoteShare`).
 *
 * Tabelle abbildbar: AI Brain `note_shares`, Manager `vault_shares` (dort ist
 * `note_shares` vom CRM belegt). `area_key` leer = persoenlicher Bereich der
 * Person `user_id`.
 *
 * @property int $id
 * @property int $user_id
 * @property string $vault_path
 * @property ?string $area_key
 * @property string $token
 * @property ?string $password_hash
 * @property int $view_count
 */
class VaultShare extends Model
{
    use Tabellenname;

    /** 24 Bytes Zufall ≈ 32 base64-url-Zeichen ≈ 192 Bit. */
    public const TOKEN_BYTES = 24;

    protected $fillable = [
        'user_id',
        'vault_path',
        'area_key',
        'token',
        'password_hash',
        'expires_at',
        'view_count',
        'revoked_at',
    ];

    protected $hidden = ['password_hash'];

    protected static function tabellenSchluessel(): string
    {
        return 'shares';
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'view_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (VaultShare $share): void {
            if (! $share->token) {
                do {
                    $share->token = static::generateToken();
                } while (static::query()->where('token', $share->token)->exists());
            }
        });
    }

    /** @return BelongsTo<Model, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo((string) config('vault.user_model'), 'user_id');
    }

    public static function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isActive(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    public function requiresPassword(): bool
    {
        return $this->password_hash !== null;
    }

    /** 'active' | 'revoked' | 'expired' */
    public function status(): string
    {
        return match (true) {
            $this->isRevoked() => 'revoked',
            $this->isExpired() => 'expired',
            default => 'active',
        };
    }
}
