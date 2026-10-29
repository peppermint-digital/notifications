// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { InboxButton } from '../src/InboxButton';

afterEach(cleanup);

beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ unread_count: 0 }), { status: 200 })));
});

function mitZahl(n: number) {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ unread_count: n }), { status: 200 })));
}

describe('Das Abzeichen', () => {
    it('zeigt die Zahl der ungelesenen Meldungen', async () => {
        mitZahl(7);

        render(<InboxButton pollMs={0} />);

        await waitFor(() => expect(screen.getByText('7')).toBeDefined());
    });

    it('zeigt gar kein Abzeichen, wenn nichts ungelesen ist', async () => {
        mitZahl(0);

        render(<InboxButton pollMs={0} />);

        await waitFor(() => expect(screen.getByRole('link')).toBeDefined());
        expect(screen.queryByText('0')).toBeNull();
    });

    it('kuerzt dreistellige Zahlen ab', async () => {
        // Dreistellig passt nicht aufs Abzeichen, und die genaue Zahl ist ab
        // da ohnehin keine Hilfe mehr.
        mitZahl(1234);

        render(<InboxButton pollMs={0} />);

        await waitFor(() => expect(screen.getByText('99+')).toBeDefined());
    });

    it('laesst die letzte Zahl stehen, wenn der Abruf scheitert', async () => {
        // Eine Null waere die falsche Aussage — sie hiesse „alles gelesen".
        vi.stubGlobal('fetch', vi.fn(async () => new Response('', { status: 500 })));

        render(<InboxButton pollMs={0} initialCount={5} />);

        await waitFor(() => expect(screen.getByText('5')).toBeDefined());
    });
});

describe('Der Klick', () => {
    it('fuehrt auf den GEMEINSAMEN Posteingang', async () => {
        /*
         * Bis zum 29.10.2026 war die Vorgabe `/notifications/inbox` — die
         * produkteigene Meldungsseite. Richtig, solange es die gemeinsame
         * Seite nicht gab; seit es sie gibt, fuehrte der Briefkasten auf
         * einen Ausschnitt (AI Brain #1152).
         */
        render(<InboxButton pollMs={0} />);

        await waitFor(() => expect(screen.getByRole('link').getAttribute('href')).toBe('/posteingang'));
    });

    it('holt die Zahl von der GLOBALEN Abfrage, nicht von der Meldungs-Zahl', async () => {
        // Sonst spricht das Abzeichen ueber die Meldungen dieses einen
        // Systems, waehrend der Klick vier Systeme aufmacht.
        const holen = vi.fn(async () => new Response(JSON.stringify({ unread_count: 168 })));
        vi.stubGlobal('fetch', holen);

        render(<InboxButton pollMs={0} />);

        await waitFor(() => expect(screen.getByText('99+')).toBeDefined());

        expect(holen).toHaveBeenCalledWith('/posteingang/ungelesen', expect.anything());
    });

    it('geht ueber den Router des Produkts, wenn einer da ist', async () => {
        // Sonst laedt der Klick die ganze Seite neu und die Anwendung
        // verliert ihren Zustand.
        const navigieren = vi.fn();

        render(<InboxButton pollMs={0} onNavigate={navigieren} />);

        fireEvent.click(screen.getByRole('link'), { button: 0 });

        expect(navigieren).toHaveBeenCalledWith('/posteingang');
    });

    it('ueberlaesst den Klick mit gedrueckter Taste dem Browser', async () => {
        // Wer „in neuem Tab oeffnen" meint, soll das bekommen.
        const navigieren = vi.fn();

        render(<InboxButton pollMs={0} onNavigate={navigieren} />);

        fireEvent.click(screen.getByRole('link'), { button: 0, metaKey: true });

        expect(navigieren).not.toHaveBeenCalled();
    });
});

describe('Vorlesbarkeit', () => {
    it('nennt die Zahl auch im Vorlesetext — und WAS sie zaehlt', async () => {
        /*
         * „in allen Systemen" ist nicht Hoeflichkeit (AI Brain #1152): Die
         * Zahl ist der Bestand ueber alle Arten und Systeme, die Reiter auf
         * der Seite dahinter zeigen die Liste. Am 29.10.2026 stand 4623 am
         * Symbol gegen Reiter mit 9/29/50/112/50, und der erste Eindruck
         * war „das kann nicht stimmen".
         */
        mitZahl(3);

        render(<InboxButton pollMs={0} />);

        await waitFor(() =>
            expect(
                screen.getByLabelText('Posteingang — 3 ungelesen in allen Systemen'),
            ).toBeDefined(),
        );
    });

    it('nennt ohne Zahl nur den Namen', async () => {
        // „0 ungelesen in allen Systemen" waere umstaendlich fuer die
        // Aussage „nichts da".
        mitZahl(0);

        render(<InboxButton pollMs={0} />);

        await waitFor(() => expect(screen.getByLabelText('Posteingang')).toBeDefined());
    });
});
