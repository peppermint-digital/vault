import { act } from 'react';
import { describe, expect, it } from 'vitest';
import { AnlegenDialog } from '../src/AnlegenDialog';
import { render } from './support/render';

const warten = () => act(async () => { await new Promise((r) => setTimeout(r, 0)); });

describe('AnlegenDialog', () => {
    it('bleibt bei einer Absage offen und zeigt sie', async () => {
        let geschlossen = false;
        let pfad = '';
        const { unmount } = render(
            <AnlegenDialog
                offen
                art="datei"
                ausgang="Projekte"
                onSchliessen={() => (geschlossen = true)}
                onBestaetigen={async (p) => {
                    pfad = p;
                    throw new Error('Unter diesem Namen gibt es hier schon etwas.');
                }}
            />,
        );

        act(() => {
            document.body.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        });
        await warten();

        expect(pfad).toBe('Projekte/Neue Notiz.md');
        expect(geschlossen).toBe(false);
        expect(document.body.querySelector('[role=alert]')?.textContent).toContain('schon etwas');
        unmount();
    });

    it('haengt .md an, wenn eine neue Datei keine Endung hat', async () => {
        let pfad = '';
        const { unmount } = render(<AnlegenDialog offen art="datei" ausgang="" onSchliessen={() => {}} onBestaetigen={async (p) => { pfad = p; }} />);

        const feld = document.body.querySelector('input')!;
        act(() => {
            Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(feld, 'Protokoll');
            feld.dispatchEvent(new Event('input', { bubbles: true }));
        });
        act(() => {
            document.body.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        });
        await warten();

        expect(pfad).toBe('Protokoll.md');
        unmount();
    });
});
