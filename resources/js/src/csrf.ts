/**
 * CSRF-Koepfe fuer die Schreibwege des Pakets (0.7.1).
 *
 * Bisher ging nur `X-CSRF-TOKEN` mit dem Wert, den das Produkt hereinreicht
 * (Meta-Tag). Ein Produkt ohne Meta-Tag (AI Brain, gefunden von aibrain-e1)
 * schickte einen leeren Kopf und bekam 419. Laravel nimmt als Rueckfall
 * `X-XSRF-TOKEN` mit dem Wert des Cookies `XSRF-TOKEN` (VerifyCsrfToken,
 * Standard in der web-Middleware) - den schicken wir jetzt immer mit, wenn
 * das Cookie da ist. `X-CSRF-TOKEN` nur noch, wenn es einen Wert gibt: Ein
 * leerer Kopf liesse Laravel den Rueckfall pruefen, ein falscher nicht.
 */
export type CsrfQuelle = string | (() => string) | undefined;

export function xsrfAusCookie(cookie: string = typeof document === 'undefined' ? '' : document.cookie): string {
    const treffer = /(?:^|;\s*)XSRF-TOKEN=([^;]*)/.exec(cookie);
    if (!treffer) {
        return '';
    }
    try {
        return decodeURIComponent(treffer[1]);
    } catch {
        return treffer[1];
    }
}

export function csrfKoepfe(quelle: CsrfQuelle): Record<string, string> {
    const wert = typeof quelle === 'function' ? quelle() : (quelle ?? '');
    const koepfe: Record<string, string> = {};
    if (wert) {
        koepfe['X-CSRF-TOKEN'] = wert;
    }
    const xsrf = xsrfAusCookie();
    if (xsrf) {
        koepfe['X-XSRF-TOKEN'] = xsrf;
    }
    return koepfe;
}
