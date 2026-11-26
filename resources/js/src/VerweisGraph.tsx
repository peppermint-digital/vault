import * as d3 from 'd3';
import { Maximize2, Search, X, ZoomIn, ZoomOut } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { VaultApi } from './transport';
import type { Verweisgraph } from './typen';
import { Button } from './ui/button';
import { Input } from './ui/input';
import { cn } from './ui/utils';

/**
 * Der Verweisgraph EINES Bereichs: Notizen als Knoten, `[[wikilinks]]` als
 * Kanten (0.5.0, aus AI Brain `modules/vault/pages/graph.tsx`).
 *
 * Bewusst je Bereich: Die Daten kommen von `GET verweise?scope=…` und laufen
 * durch dieselbe Leserecht-Pruefung wie jeder andere Weg. Einen Graph ueber
 * mehrere Bereiche gibt es nicht — so entsteht nie ein Knoten aus einem
 * Bereich, den die Person nicht lesen darf.
 *
 * Aussehen wie in AI Brain (Werkzeugleiste, Hover-Kasten unten links,
 * Legende unten rechts). Seite, Layout und Bereichswahl gehoeren dem Produkt
 * (`Bereichsreiter`).
 */
export interface VerweisGraphProps {
    api: VaultApi;
    scope: string;
    /** Diese Notiz hervorheben und in die Mitte holen. */
    aktuellerPfad?: string;
    /** Klick auf einen Knoten. */
    onOeffnen: (pfad: string) => void;
    className?: string;
}

interface SimNode extends d3.SimulationNodeDatum {
    id: string;
    label: string;
    path: string;
    linkCount: number;
}

interface SimLink extends d3.SimulationLinkDatum<SimNode> {
    source: SimNode | string;
    target: SimNode | string;
}

type Zustand = { art: 'laedt' } | { art: 'fehler'; meldung: string } | { art: 'da'; graph: Verweisgraph };

export function VerweisGraph({ api, scope, aktuellerPfad = '', onOeffnen, className }: VerweisGraphProps) {
    const [zustand, setZustand] = useState<Zustand>({ art: 'laedt' });
    const [suche, setSuche] = useState('');
    const [ueber, setUeber] = useState<SimNode | null>(null);
    const svgRef = useRef<SVGSVGElement>(null);
    const behaelterRef = useRef<HTMLDivElement>(null);
    const zoomRef = useRef<d3.ZoomBehavior<SVGSVGElement, unknown> | null>(null);
    const oeffnenRef = useRef(onOeffnen);
    oeffnenRef.current = onOeffnen;

    useEffect(() => {
        let ab = false;
        setZustand({ art: 'laedt' });
        api.verweise(scope)
            .then((graph) => !ab && setZustand({ art: 'da', graph }))
            .catch((e: Error) => !ab && setZustand({ art: 'fehler', meldung: e.message }));
        return () => {
            ab = true;
        };
    }, [api, scope]);

    const zoomen = useCallback((faktor: number | null) => {
        if (!svgRef.current || !zoomRef.current) {
            return;
        }
        const auswahl = d3.select(svgRef.current).transition().duration(300);
        if (faktor === null) {
            auswahl.call(zoomRef.current.transform, d3.zoomIdentity);
        } else {
            auswahl.call(zoomRef.current.scaleBy, faktor);
        }
    }, []);

    const graph = zustand.art === 'da' ? zustand.graph : null;

    useEffect(() => {
        if (!graph || !svgRef.current || !behaelterRef.current || graph.nodes.length === 0) {
            return;
        }

        const breite = behaelterRef.current.clientWidth;
        const hoehe = behaelterRef.current.clientHeight;
        d3.select(svgRef.current).selectAll('*').remove();

        const svg = d3.select(svgRef.current).attr('width', breite).attr('height', hoehe);
        svg.append('rect').attr('width', breite).attr('height', hoehe).attr('fill', 'transparent');
        const g = svg.append('g');

        const zoom = d3
            .zoom<SVGSVGElement, unknown>()
            .scaleExtent([0.1, 8])
            .on('zoom', (ereignis) => g.attr('transform', ereignis.transform));
        zoomRef.current = zoom;
        svg.call(zoom);

        const knotenNach = new Map<string, SimNode>();
        const knoten: SimNode[] = graph.nodes.map((n) => {
            const k: SimNode = { ...n };
            knotenNach.set(n.id, k);
            return k;
        });
        const kanten: SimLink[] = graph.edges
            .filter((e) => knotenNach.has(e.source) && knotenNach.has(e.target))
            .map((e) => ({ source: e.source, target: e.target }));

        const idVon = (ende: SimNode | string) => (typeof ende === 'string' ? ende : ende.id);
        const nachbarnVon = (id: string) => {
            const menge = new Set<string>();
            kanten.forEach((l) => {
                if (idVon(l.source) === id) menge.add(idVon(l.target));
                if (idVon(l.target) === id) menge.add(idVon(l.source));
            });
            return menge;
        };
        const verbunden = aktuellerPfad ? nachbarnVon(aktuellerPfad) : new Set<string>();

        const simulation = d3
            .forceSimulation<SimNode>(knoten)
            .force('link', d3.forceLink<SimNode, SimLink>(kanten).id((d) => d.id).distance(110).strength(0.3))
            .force('charge', d3.forceManyBody<SimNode>().strength(-900).distanceMax(1200))
            .force('x', d3.forceX(breite / 2).strength(0.02))
            .force('y', d3.forceY(hoehe / 2).strength(0.02))
            .force('collision', d3.forceCollide<SimNode>().radius((d) => radius(d) + 20).strength(0.9))
            .alphaDecay(0.015);

        const linie = g
            .append('g')
            .selectAll('line')
            .data(kanten)
            .join('line')
            .attr('stroke', 'currentColor')
            .attr('stroke-opacity', 0.25)
            .attr('stroke-width', 1.5);

        const gruppe = g
            .append('g')
            .attr('class', 'nodes')
            .selectAll<SVGGElement, SimNode>('g')
            .data(knoten)
            .join('g')
            .style('cursor', 'pointer')
            .call(
                d3
                    .drag<SVGGElement, SimNode>()
                    .on('start', (ereignis, d) => {
                        if (!ereignis.active) simulation.alphaTarget(0.3).restart();
                        d.fx = d.x;
                        d.fy = d.y;
                    })
                    .on('drag', (ereignis, d) => {
                        d.fx = ereignis.x;
                        d.fy = ereignis.y;
                    })
                    .on('end', (ereignis, d) => {
                        if (!ereignis.active) simulation.alphaTarget(0);
                        d.fx = null;
                        d.fy = null;
                    }),
            );

        // Kreis = Notiz, abgerundetes Quadrat = HTML; Farbe = Ordner.
        gruppe.each(function (d) {
            const r = radius(d);
            const auswahl = d3.select<SVGGElement, SimNode>(this);
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const form: d3.Selection<any, SimNode, null, undefined> = /\.html$/i.test(d.path)
                ? auswahl.append('rect').attr('x', -r).attr('y', -r).attr('width', r * 2).attr('height', r * 2).attr('rx', 3)
                : auswahl.append('circle').attr('r', r);
            form.attr('class', 'knoten-form')
                .attr('fill', d.id === aktuellerPfad ? 'var(--primary)' : ordnerfarbe(d.id))
                .attr('stroke', d.id === aktuellerPfad ? 'var(--primary)' : verbunden.has(d.id) ? 'var(--ring)' : 'var(--border)')
                .attr('stroke-width', d.id === aktuellerPfad ? 2 : verbunden.has(d.id) ? 1.5 : 1)
                .attr('fill-opacity', 0.9);
        });

        const deckkraft = (d: SimNode) => (d.id === aktuellerPfad || verbunden.has(d.id) || d.linkCount > 2 ? 1 : 0.6);
        gruppe
            .append('text')
            .text((d) => d.label)
            .attr('dy', (d) => radius(d) + 12)
            .attr('text-anchor', 'middle')
            .attr('font-size', '10px')
            .attr('fill', 'currentColor')
            .attr('pointer-events', 'none')
            .attr('opacity', deckkraft);

        gruppe
            .on('mouseenter', function (_, d) {
                setUeber(d);
                const nah = nachbarnVon(d.id).add(d.id);
                gruppe.transition().duration(150).style('opacity', (n) => (nah.has(n.id) ? 1 : 0.12));
                linie
                    .attr('stroke-opacity', (l) => (idVon(l.source) === d.id || idVon(l.target) === d.id ? 0.7 : 0.04))
                    .attr('stroke-width', (l) => (idVon(l.source) === d.id || idVon(l.target) === d.id ? 2.5 : 1));
            })
            .on('mouseleave', function () {
                setUeber(null);
                gruppe.transition().duration(150).style('opacity', 1);
                linie.attr('stroke-opacity', 0.25).attr('stroke-width', 1.5);
            })
            .on('click', (_, d) => oeffnenRef.current(d.path));

        simulation.on('tick', () => {
            linie
                .attr('x1', (d) => (d.source as SimNode).x ?? 0)
                .attr('y1', (d) => (d.source as SimNode).y ?? 0)
                .attr('x2', (d) => (d.target as SimNode).x ?? 0)
                .attr('y2', (d) => (d.target as SimNode).y ?? 0);
            gruppe.attr('transform', (d) => `translate(${d.x ?? 0},${d.y ?? 0})`);
        });

        let zeitgeber: ReturnType<typeof setTimeout> | undefined;
        if (aktuellerPfad) {
            zeitgeber = setTimeout(() => {
                const k = knotenNach.get(aktuellerPfad);
                if (k?.x !== undefined && k?.y !== undefined && svgRef.current) {
                    const s = 1.5;
                    d3.select(svgRef.current)
                        .transition()
                        .duration(600)
                        .call(zoom.transform, d3.zoomIdentity.translate(breite / 2 - s * k.x, hoehe / 2 - s * k.y).scale(s));
                }
            }, 1200);
        }

        return () => {
            simulation.stop();
            if (zeitgeber) clearTimeout(zeitgeber);
        };
    }, [graph, aktuellerPfad]);

    useEffect(() => {
        if (!svgRef.current) {
            return;
        }
        const begriff = suche.toLowerCase();
        d3.select(svgRef.current)
            .selectAll<SVGGraphicsElement, SimNode>('.nodes .knoten-form')
            .attr('fill-opacity', (d) => (!begriff ? 0.9 : d.label.toLowerCase().includes(begriff) ? 1 : 0.15));
    }, [suche, graph]);

    return (
        <div className={cn('flex min-h-[60vh] flex-col overflow-hidden rounded-lg border', className)}>
            <div className="flex shrink-0 flex-wrap items-center gap-2 border-b px-3 py-2">
                <div className="relative w-52">
                    <Search className="absolute top-1/2 left-2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                    <Input placeholder="Notiz suchen…" aria-label="Im Graph suchen" value={suche} onChange={(e) => setSuche(e.target.value)} className="h-8 pr-7 pl-7 text-sm" />
                    {suche !== '' && (
                        <button type="button" aria-label="Suche leeren" onClick={() => setSuche('')} className="absolute top-1/2 right-2 -translate-y-1/2 text-muted-foreground hover:text-foreground">
                            <X className="size-3" />
                        </button>
                    )}
                </div>
                <div className="ml-auto flex items-center gap-1">
                    {graph && (
                        <span className="mr-2 text-xs text-muted-foreground">
                            {graph.nodes.length} Notizen · {graph.edges.length} Verweise
                        </span>
                    )}
                    <Button variant="ghost" size="icon" className="size-8" onClick={() => zoomen(1.4)} aria-label="Hineinzoomen" title="Hineinzoomen">
                        <ZoomIn />
                    </Button>
                    <Button variant="ghost" size="icon" className="size-8" onClick={() => zoomen(0.7)} aria-label="Herauszoomen" title="Herauszoomen">
                        <ZoomOut />
                    </Button>
                    <Button variant="ghost" size="icon" className="size-8" onClick={() => zoomen(null)} aria-label="Ansicht zuruecksetzen" title="Zurücksetzen">
                        <Maximize2 />
                    </Button>
                </div>
            </div>

            <div ref={behaelterRef} className="relative flex-1 overflow-hidden">
                {zustand.art === 'laedt' && <div className="m-4 h-[calc(100%-2rem)] animate-pulse rounded-md bg-muted" aria-label="Graph wird geladen" />}
                {zustand.art === 'fehler' && (
                    <p role="alert" className="p-4 text-sm text-destructive">
                        {zustand.meldung}
                    </p>
                )}
                {graph && graph.nodes.length === 0 && (
                    <div className="flex h-full items-center justify-center p-6 text-center text-muted-foreground">
                        <div>
                            <p>In diesem Bereich gibt es noch keine Verweise.</p>
                            <p className="mt-1 text-sm">Verweise entstehen mit [[Name der Notiz]] in einer Notiz.</p>
                        </div>
                    </div>
                )}
                {graph && graph.nodes.length > 0 && <svg ref={svgRef} className="h-full w-full text-foreground" />}

                {ueber && (
                    <div className="pointer-events-none absolute bottom-4 left-4 rounded-md border bg-popover px-3 py-2 text-sm text-popover-foreground shadow-md">
                        <p className="font-medium">{ueber.label}</p>
                        <p className="mt-0.5 text-xs text-muted-foreground">{ueber.path}</p>
                        {ueber.linkCount > 0 && (
                            <p className="text-xs text-muted-foreground">
                                {ueber.linkCount} {ueber.linkCount === 1 ? 'eingehender Verweis' : 'eingehende Verweise'}
                            </p>
                        )}
                    </div>
                )}

                {graph && graph.nodes.length > 0 && (
                    <div className="pointer-events-none absolute right-4 bottom-4 flex flex-col gap-1.5 rounded-md border bg-popover px-3 py-2 text-xs text-muted-foreground shadow-md">
                        <div className="flex items-center gap-2">
                            <span className="inline-block h-3 w-3 rounded-full bg-primary" />
                            Aktuelle Notiz
                        </div>
                        <div className="flex items-center gap-2">
                            <span
                                className="inline-block h-3 w-3 rounded-full"
                                style={{ background: 'linear-gradient(135deg, hsl(217,75%,60%), hsl(142,60%,45%), hsl(280,60%,60%))' }}
                            />
                            Farbe = Ordner
                        </div>
                        <div className="flex items-center gap-2">
                            <span className="inline-block h-3 w-3 rounded-full bg-muted-foreground/50" />
                            <span>Markdown</span>
                            <span className="ml-2 inline-block h-3 w-3 rounded-[2px] bg-muted-foreground/50" />
                            <span>HTML</span>
                        </div>
                        <div className="flex items-center gap-2">
                            <span className="text-[10px]">⬤ groß</span>
                            Viele eingehende Verweise
                        </div>
                        <p className="mt-1 border-t pt-1">
                            Klick → Notiz öffnen
                            <br />
                            Ziehen → Position fixieren
                        </p>
                    </div>
                )}
            </div>
        </div>
    );
}

function radius(knoten: SimNode): number {
    return 5 + Math.min(knoten.linkCount * 1.5, 12);
}

const ORDNERFARBEN = [
    'hsl(217, 75%, 60%)',
    'hsl(142, 60%, 45%)',
    'hsl(38, 85%, 55%)',
    'hsl(280, 60%, 60%)',
    'hsl(340, 70%, 55%)',
    'hsl(175, 55%, 45%)',
    'hsl(50, 80%, 50%)',
    'hsl(0, 65%, 55%)',
    'hsl(195, 70%, 50%)',
    'hsl(110, 50%, 50%)',
    'hsl(255, 50%, 65%)',
    'hsl(25, 70%, 50%)',
];

/** Gleiche Farbe je oberstem Ordner (bei `topics/` eine Ebene tiefer), wie in AI Brain. */
export function ordnerfarbe(pfad: string): string {
    const teile = pfad.split('/').filter(Boolean);
    if (teile.length < 2) {
        return 'var(--muted-foreground)';
    }
    const schluessel = teile[0] === 'topics' && teile.length >= 3 ? `topics/${teile[1]}` : teile[0];
    let summe = 0;
    for (let i = 0; i < schluessel.length; i++) {
        summe = (summe * 31 + schluessel.charCodeAt(i)) & 0xffff;
    }
    return ORDNERFARBEN[summe % ORDNERFARBEN.length];
}

export default VerweisGraph;
