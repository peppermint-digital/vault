<?php

namespace Peppermint\Vault\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Person ↔ Adresse.
 *
 * Nutzer 5 ist in jedem Produkt jemand anderes (Muster mailbox `user_model`).
 * Wer Dateien von einem Produkt ins andere traegt — der Umzug des geteilten
 * Bereichs aus AI Brain in den Manager —, ordnet Autoren ueber die Adresse zu,
 * nie ueber die ID.
 */
interface Benutzeradresse
{
    public function adresse(Authenticatable $user): ?string;

    public function person(string $adresse): ?Authenticatable;
}
