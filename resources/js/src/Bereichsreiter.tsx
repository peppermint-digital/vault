import { Globe, HardDrive, Lock, TriangleAlert, Users, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from './ui/button';
import { cn } from './ui/utils';

/**
 * Welcher Bereich gerade offen ist — oben auf Vault- und Graph-Seite (0.5.0).
 *
 * Aufbau und Aussehen sind die der Bereichsauswahl in AI Brain
 * (`modules/vault/pages/index.tsx`, Stand 26.11.2026): Karte, je Bereich ein
 * kleiner Knopf (offen = gefuellt, sonst umrandet) mit Symbol, darunter eine
 * Zeile, was der Bereich ist und ob man bearbeiten darf. Bastian 26.11.: „Wir
 * behalten aber unser Design … nur die Teilstücke anpassen, dass es auf beiden
 * gleich aussieht."
 *
 * Die Liste kommt vom Server (`Navigation::bereiche()`): nur, was die Person
 * lesen darf. Eigenes (AI Brain: „Neuer Space…") reicht das Produkt als
 * `children` herein; es steht rechts.
 */
export interface Bereichsreiterpunkt {
    slug: string;
    label: string;
    art?: 'persoenlich' | 'global' | 'raum' | 'quelle';
    persoenlich?: boolean;
    darfSchreiben?: boolean;
    /** Nur bei Quellen (0.6.4): `false` = ausgeschaltet oder nicht eingehaengt. */
    erreichbar?: boolean;
}

export interface BereichsreiterProps {
    bereiche: Bereichsreiterpunkt[];
    aktiv: string;
    onWechsel: (slug: string) => void;
    children?: ReactNode;
    className?: string;
}

const SYMBOL: Record<NonNullable<Bereichsreiterpunkt['art']>, LucideIcon> = {
    persoenlich: Lock,
    global: Globe,
    raum: Users,
    quelle: HardDrive,
};

function artVon(bereich: Bereichsreiterpunkt): NonNullable<Bereichsreiterpunkt['art']> {
    return bereich.art ?? (bereich.persoenlich ? 'persoenlich' : 'raum');
}

function beschreibung(bereich: Bereichsreiterpunkt): string {
    const art = artVon(bereich);
    if (art === 'persoenlich') {
        return 'Dein persönlicher Vault — nur du siehst und bearbeitest ihn.';
    }
    if (art === 'quelle' && bereich.erreichbar === false) {
        return 'Extern angebundene Quelle — gerade nicht erreichbar. Die Dateien sind nicht weg, der Speicher antwortet nur nicht.';
    }
    const was =
        art === 'quelle'
            ? 'Extern angebundene Quelle — keine Versionen, kein Papierkorb: Gelöschtes ist dort sofort weg. '
            : art === 'global'
              ? 'Offener Bereich — alle lesen. '
              : 'Benannter Raum — nur Mitglieder. ';

    return was + (bereich.darfSchreiben ? 'Du kannst bearbeiten.' : 'Nur Lesen.');
}

export function Bereichsreiter({ bereiche, aktiv, onWechsel, children, className }: BereichsreiterProps) {
    const offen = bereiche.find((b) => b.slug === aktiv) ?? null;

    if (bereiche.length === 0) {
        return null;
    }

    return (
        <div className={cn('rounded-xl border bg-card px-4 py-3', className)}>
            <div className="flex flex-wrap items-center gap-2">
                <div className="flex flex-wrap gap-1" role="group" aria-label="Bereich">
                    {bereiche.map((bereich) => {
                        const gewaehlt = bereich.slug === aktiv;
                        const nichtErreichbar = artVon(bereich) === 'quelle' && bereich.erreichbar === false;
                        const Symbol = nichtErreichbar ? TriangleAlert : SYMBOL[artVon(bereich)];
                        return (
                            <Button
                                key={bereich.slug === '' ? '__persoenlich' : bereich.slug}
                                type="button"
                                size="sm"
                                variant={gewaehlt ? 'default' : 'outline'}
                                aria-pressed={gewaehlt}
                                onClick={() => !gewaehlt && onWechsel(bereich.slug)}
                                className="gap-1"
                                title={nichtErreichbar ? 'Gerade nicht erreichbar' : undefined}
                            >
                                <Symbol className={cn('h-3 w-3', nichtErreichbar && 'text-amber-500')} aria-label={nichtErreichbar ? 'nicht erreichbar' : undefined} />
                                {bereich.label}
                            </Button>
                        );
                    })}
                </div>
                {children && <div className="ml-auto flex gap-1">{children}</div>}
            </div>
            {offen && <div className="mt-1 pl-1 text-sm text-muted-foreground">{beschreibung(offen)}</div>}
        </div>
    );
}

export default Bereichsreiter;
