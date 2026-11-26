<?php

namespace Peppermint\Vault\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Peppermint\Vault\Contracts\Verknuepfbar;
use Peppermint\Vault\Verknuepfung\HatVaultDateien;

class Aufgabe extends Model implements Verknuepfbar
{
    use HatVaultDateien;

    protected $table = 'aufgaben';

    protected $guarded = [];

    public function getMorphClass(): string
    {
        return 'aufgabe';
    }
}
