<?php

namespace Peppermint\Vault\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Peppermint\Vault\Bereich;

/**
 * Der persoenliche Bereich einer neuen Person ist angelegt.
 *
 * Der Haken anstelle von AI Brain `User.php:251`: Dort legt AI Brain Persona
 * und Startdateien an, der Manager nur den Arbeits-Vault.
 */
class KontoAngelegt
{
    public function __construct(
        public readonly Authenticatable $user,
        public readonly Bereich $bereich,
        public readonly bool $neu,
    ) {}
}
