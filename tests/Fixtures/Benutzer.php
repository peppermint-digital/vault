<?php

namespace Peppermint\Vault\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;

/** Ein Benutzer mit hasRole()/can() wie Spatie — ohne Spatie. */
class Benutzer extends User
{
    protected $table = 'users';

    protected $guarded = [];

    /** @var list<string> */
    public array $rechte = [];

    public function hasRole(string $rolle): bool
    {
        return $this->rolle === $rolle;
    }

    public function can($recht, $arguments = []): bool
    {
        return in_array($recht, $this->rechte, true);
    }
}
