import { Lock } from 'lucide-react';
import { useCallback, useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { Button } from './ui/button';
import { Input } from './ui/input';
import { Label } from './ui/label';

/**
 * Die oeffentliche Seite eines Freigabelinks (AI Brain `pages/shared/show`
 * und `shared/password`). Das Produkt rendert sie unter `/geteilt/{token}`
 * ohne Anmeldung und bettet diesen Baustein ein.
 *
 * HTML laeuft in einem iframe mit `sandbox="allow-scripts"` OHNE
 * `allow-same-origin`: Die Seite bekommt eine eigene, leere Herkunft und
 * erreicht weder die Cookies noch die API des Produkts — zusaetzlich zur
 * Content-Security-Policy, die der Server mitschickt.
 */
export interface GeteilteAnsichtProps {
    token: string;
    /** Die oeffentliche Schiene, Vorgabe `/vault/geteilt`. */
    basisUrl?: string;
    csrfToken: string | (() => string);
    renderMarkdown?: (inhalt: string) => ReactNode;
    fetch?: typeof globalThis.fetch;
}

interface Info {
    name: string;
    format: 'md' | 'html';
    sharedBy: string | null;
    expiresAt: string | null;
    rohUrl: string;
    gesperrt: boolean;
    content: string | null;
}

type Zustand = { art: 'laedt' } | { art: 'fehler'; text: string } | { art: 'da'; info: Info };

export function GeteilteAnsicht({ token, basisUrl = '/vault/geteilt', csrfToken, renderMarkdown, fetch: holenVorgabe }: GeteilteAnsichtProps) {
    const holen = holenVorgabe ?? ((...a: Parameters<typeof fetch>) => globalThis.fetch(...a));
    const basis = `${basisUrl.replace(/\/+$/, '')}/${encodeURIComponent(token)}`;
    const [zustand, setZustand] = useState<Zustand>({ art: 'laedt' });
    const [passwort, setPasswort] = useState('');
    const [passwortFehler, setPasswortFehler] = useState<string | null>(null);

    const laden = useCallback(async () => {
        try {
            const antwort = await holen(`${basis}/info`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (antwort.status === 404) {
                return setZustand({ art: 'fehler', text: 'Diesen Link gibt es nicht.' });
            }
            if (antwort.status === 410) {
                return setZustand({ art: 'fehler', text: 'Dieser Link ist abgelaufen oder wurde zurückgezogen.' });
            }
            if (!antwort.ok) {
                return setZustand({ art: 'fehler', text: 'Der Inhalt ließ sich nicht laden.' });
            }
            setZustand({ art: 'da', info: (await antwort.json()) as Info });
        } catch {
            setZustand({ art: 'fehler', text: 'Keine Verbindung zum Server.' });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [basis]);

    useEffect(() => {
        void laden();
    }, [laden]);

    async function entsperren(e: FormEvent) {
        e.preventDefault();
        setPasswortFehler(null);
        const antwort = await holen(`${basis}/entsperren`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': typeof csrfToken === 'function' ? csrfToken() : csrfToken,
            },
            body: JSON.stringify({ password: passwort }),
        }).catch(() => null);

        if (antwort?.ok) {
            setPasswort('');
            await laden();
        } else {
            setPasswortFehler(antwort?.status === 429 ? 'Zu viele Versuche. Bitte kurz warten.' : 'Falsches Passwort.');
        }
    }

    if (zustand.art === 'laedt') {
        return <p className="p-6 text-sm text-muted-foreground">Wird geladen…</p>;
    }
    if (zustand.art === 'fehler') {
        return <p role="alert" className="p-6 text-sm">{zustand.text}</p>;
    }

    const { info } = zustand;

    if (info.gesperrt) {
        return (
            <form onSubmit={entsperren} className="mx-auto grid max-w-sm gap-3 p-6">
                <h1 className="flex items-center gap-2 text-lg font-medium">
                    <Lock className="size-5" /> {info.name}
                </h1>
                <p className="text-sm text-muted-foreground">{info.sharedBy ? `${info.sharedBy} hat` : 'Diese Datei ist'} mit einem Passwort geschützt.</p>
                <div className="grid gap-1.5">
                    <Label htmlFor="vault-geteilt-passwort">Passwort</Label>
                    <Input id="vault-geteilt-passwort" type="password" autoFocus value={passwort} onChange={(e) => setPasswort(e.target.value)} />
                    {passwortFehler && <p role="alert" className="text-sm text-destructive">{passwortFehler}</p>}
                </div>
                <Button type="submit">Öffnen</Button>
            </form>
        );
    }

    return (
        <article className="mx-auto grid w-full max-w-4xl gap-3 p-4">
            <header className="flex flex-wrap items-baseline justify-between gap-2 border-b pb-2">
                <h1 className="text-lg font-medium">{info.name}</h1>
                <span className="text-xs text-muted-foreground">
                    {info.sharedBy ? `Geteilt von ${info.sharedBy}` : ''}
                    {info.expiresAt ? ` · gültig bis ${new Date(info.expiresAt).toLocaleDateString('de-DE')}` : ''}
                </span>
            </header>
            {info.format === 'html' ? (
                <iframe title={info.name} src={info.rohUrl} sandbox="allow-scripts allow-popups" className="h-[80vh] w-full rounded-md border" />
            ) : renderMarkdown ? (
                renderMarkdown(info.content ?? '')
            ) : (
                <pre className="text-sm whitespace-pre-wrap">{info.content}</pre>
            )}
        </article>
    );
}

export default GeteilteAnsicht;
