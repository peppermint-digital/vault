/**
 * Ein fetch, das wie die JSON-Schiene antwortet — je Methode und Pfad eine
 * Antwort, und jede Anfrage wird mitgeschrieben.
 */
export interface Mitschrift {
    methode: string;
    url: string;
    kopf: Record<string, string>;
    rumpf: unknown;
}

type Antwort = { status?: number; json: unknown } | ((m: Mitschrift) => { status?: number; json: unknown });

export function falscherServer(routen: Record<string, Antwort>) {
    const mitschrift: Mitschrift[] = [];

    const holen = (async (eingabe: RequestInfo | URL, init?: RequestInit) => {
        const url = String(eingabe);
        const methode = init?.method ?? 'GET';
        const rumpf = typeof init?.body === 'string' ? JSON.parse(init.body) : (init?.body ?? null);
        const eintrag: Mitschrift = { methode, url, kopf: (init?.headers ?? {}) as Record<string, string>, rumpf };
        mitschrift.push(eintrag);

        const pfad = url.replace(/^\/vault\/api\//, '').split('?')[0];
        const treffer = routen[`${methode} ${pfad}`];
        if (!treffer) {
            return new Response(JSON.stringify({ message: 'nicht vorgesehen' }), { status: 500 });
        }
        const a = typeof treffer === 'function' ? treffer(eintrag) : treffer;

        return new Response(JSON.stringify(a.json), { status: a.status ?? 200, headers: { 'Content-Type': 'application/json' } });
    }) as typeof fetch;

    return { holen, mitschrift };
}
