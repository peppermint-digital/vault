import type { ReactNode } from 'react';
import { cn } from './ui/utils';

/**
 * Der Kopf der Vault-Seiten (0.5.1, Bastian 26.11.: „F52 ja"): Ueberschrift
 * und Untertitel wie in AI Brain (`modules/vault/pages/index.tsx`, Standard-
 * Muster „Titel + Subheadline, ohne Icon"), in jedem Produkt gleich.
 *
 * Rechts steht nur, was das Produkt mitgibt — AI Brain den „Assistent", der
 * Manager (noch) nichts.
 */
export interface VaultSeitenkopfProps {
    titel?: string;
    untertitel?: string;
    children?: ReactNode;
    className?: string;
}

export const VAULT_UNTERTITEL = 'Dein privater Vault und die geteilten Bereiche — alles an einem Ort.';
export const GRAPH_UNTERTITEL = 'Wie die Notizen dieses Bereichs über Verweise zusammenhängen.';

export function VaultSeitenkopf({ titel = 'Vault', untertitel = VAULT_UNTERTITEL, children, className }: VaultSeitenkopfProps) {
    return (
        <div className={cn('flex flex-wrap items-start justify-between gap-2', className)}>
            <div>
                <h1 className="text-2xl font-bold">{titel}</h1>
                {untertitel !== '' && <p className="text-muted-foreground">{untertitel}</p>}
            </div>
            {children && <div className="flex shrink-0 gap-1">{children}</div>}
        </div>
    );
}

export default VaultSeitenkopf;
