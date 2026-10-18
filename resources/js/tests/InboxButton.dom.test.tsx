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
    it('fuehrt auf die Posteingangs-Seite', async () => {
        render(<InboxButton pollMs={0} />);

        await waitFor(() => expect(screen.getByRole('link').getAttribute('href')).toBe('/notifications/inbox'));
    });

    it('geht ueber den Router des Produkts, wenn einer da ist', async () => {
        // Sonst laedt der Klick die ganze Seite neu und die Anwendung
        // verliert ihren Zustand.
        const navigieren = vi.fn();

        render(<InboxButton pollMs={0} onNavigate={navigieren} />);

        fireEvent.click(screen.getByRole('link'), { button: 0 });

        expect(navigieren).toHaveBeenCalledWith('/notifications/inbox');
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
    it('nennt die Zahl auch im Vorlesetext', async () => {
        mitZahl(3);

        render(<InboxButton pollMs={0} />);

        await waitFor(() =>
            expect(screen.getByLabelText('Posteingang — 3 ungelesen')).toBeDefined(),
        );
    });
});
