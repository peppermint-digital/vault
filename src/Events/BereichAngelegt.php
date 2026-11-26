<?php

namespace Peppermint\Vault\Events;

use Peppermint\Vault\Bereich;

/** Ein Bereich existiert jetzt auf der Platte (persoenlich oder Raum). */
class BereichAngelegt
{
    public function __construct(public readonly Bereich $bereich) {}
}
