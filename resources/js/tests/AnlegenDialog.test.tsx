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

    function tippen(wert: string) {
        const feld = document.body.querySelector<HTMLInputElement>('#vault-name')!;
        act(() => {
            Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(feld, wert);
            feld.dispatchEvent(new Event('input', { bubbles: true }));
        });
    }

    function absenden() {
        act(() => {
            document.body.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        });
    }

    it('zeigt Office-Arten nur, wenn das Produkt sie anbietet', () => {
        const ohne = render(<AnlegenDialog offen art="datei" ausgang="" onSchliessen={() => {}} onBestaetigen={async () => {}} />);
        expect(document.body.querySelectorAll('input[type=radio]').length).toBe(0);
        ohne.unmount();

        const mit = render(<AnlegenDialog offen art="datei" ausgang="" formate={['md', 'docx', 'xlsx', 'pptx']} onSchliessen={() => {}} onBestaetigen={async () => {}} />);
        expect(document.body.querySelectorAll('input[type=radio]').length).toBe(4);
        mit.unmount();
    });

    it('legt mit der gewaehlten Art an und nimmt deren Namensvorschlag', async () => {
        let pfad = '';
        const { unmount } = render(
            <AnlegenDialog offen art="datei" ausgang="Kunden" formate={['md', 'xlsx']} onSchliessen={() => {}} onBestaetigen={async (p) => { pfad = p; }} />,
        );

        act(() => {
            document.body.querySelector<HTMLInputElement>('input[value=xlsx]')!.click();
        });
        expect(document.body.querySelector<HTMLInputElement>('#vault-name')!.value).toBe('Neue Tabelle');
        absenden();
        await warten();

        expect(pfad).toBe('Kunden/Neue Tabelle.xlsx');
        unmount();
    });

    it('nimmt eine getippte bekannte Endung als Wahl der Art', async () => {
        let pfad = '';
        const { unmount } = render(<AnlegenDialog offen art="datei" ausgang="" formate={['md', 'docx']} onSchliessen={() => {}} onBestaetigen={async (p) => { pfad = p; }} />);

        tippen('Angebot.docx');
        expect(document.body.querySelector<HTMLInputElement>('input[value=docx]')!.checked).toBe(true);
        absenden();
        await warten();

        expect(pfad).toBe('Angebot.docx');
        unmount();
    });

    it('warnt vor einem vergebenen Namen, bevor etwas abgeschickt wird', async () => {
        let gerufen = false;
        const { unmount } = render(
            <AnlegenDialog offen art="datei" ausgang="" vorhanden={['Protokoll.md']} onSchliessen={() => {}} onBestaetigen={async () => { gerufen = true; }} />,
        );

        tippen('protokoll');
        expect(document.body.querySelector('[role=alert]')?.textContent).toContain('schon etwas');
        expect(document.body.querySelector<HTMLButtonElement>('button[type=submit]')!.disabled).toBe(true);
        absenden();
        await warten();

        expect(gerufen).toBe(false);
        unmount();
    });

    it('laesst beim Umbenennen den eigenen Namen zu', async () => {
        let pfad = '';
        const { unmount } = render(
            <AnlegenDialog offen art="umbenennen" ausgang="A/Plan.md" vorhanden={['Plan.md']} onSchliessen={() => {}} onBestaetigen={async (p) => { pfad = p; }} />,
        );

        // Nur Gross-/Kleinschreibung geaendert: der eigene Name gilt nicht als vergeben.
        tippen('plan.md');
        expect(document.body.querySelector('[role=alert]')).toBeNull();
        absenden();
        await warten();

        expect(pfad).toBe('A/plan.md');
        unmount();
    });
});
