<?php

namespace Peppermint\Vault\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Peppermint\Vault\Models\VaultTrash;
use Peppermint\Vault\Papierkorb;

/**
 * Raeumt den Papierkorb auf (AI Brain `vault:trash:purge`, #6223). Ein
 * Papierkorb, der nie geleert wird, ist eine zweite, unsichtbare Kopie des
 * Vaults. `papierkorb.tage = 0` schaltet das ab.
 */
class PapierkorbLeerenCommand extends Command
{
    protected $signature = 'vault:papierkorb-leeren {--dry-run : Nur zeigen, wie viel entfernt wuerde}';

    protected $description = 'Entfernt abgelaufene Eintraege aus dem Vault-Papierkorb';

    public function handle(Papierkorb $papierkorb): int
    {
        $tage = VaultTrash::aufbewahrungstage();

        if ($tage === 0) {
            $this->info('Aufbewahrung ist abgeschaltet (vault.papierkorb.tage = 0) — nichts zu tun.');

            return self::SUCCESS;
        }

        $abgelaufen = VaultTrash::query()->where('deleted_at', '<', now()->subDays($tage))->get();

        if ($this->option('dry-run')) {
            $this->info("{$abgelaufen->count()} Eintraege waeren entfernt worden (aelter als {$tage} Tage).");

            return self::SUCCESS;
        }

        $entfernt = 0;
        foreach ($abgelaufen as $eintrag) {
            try {
                $papierkorb->leeren($eintrag);
                $entfernt++;
            } catch (\Throwable $e) {
                // Eine kaputte Zeile darf das Aufraeumen der anderen nicht blockieren.
                Log::warning('vault: Papierkorb-Eintrag liess sich nicht entfernen', ['id' => $eintrag->id, 'fehler' => $e->getMessage()]);
            }
        }

        $this->info("{$entfernt} Eintraege entfernt (aelter als {$tage} Tage).");

        return self::SUCCESS;
    }
}
