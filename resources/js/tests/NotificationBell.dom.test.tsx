// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { NotificationBell } from '../src/NotificationBell';
import type { NotificationItem } from '../src/types';

afterEach(cleanup);

function meldung(ueberschreiben: Partial<NotificationItem> = {}): NotificationItem {
    return {
        id: 1,
        ids: [1],
        bundleCount: 1,
        type: 'mention',
        typeLabel: 'Erwähnung',
        title: 'Du wurdest erwähnt',
        message: null,
        icon: 'at-sign',
        color: 'cyan',
        link: null,
        triggeredBy: null,
        triggeredById: null,
        subjectType: null,
        subjectId: null,
        isRead: false,
        createdAt: '2026-10-16T10:00:00+00:00',
        source: null,
        ...ueberschreiben,
    };
}

const leer = { onResolve: vi.fn(), onDelete: vi.fn(), items: [] as NotificationItem[] };

describe('Abzeichen', () => {
    it('zeigt die Zahl der ungelesenen Meldungen', () => {
        render(<NotificationBell {...leer} unreadCount={7} />);

        expect(screen.getByText('7')).toBeDefined();
    });

    it('zeigt kein Abzeichen bei null', () => {
        render(<NotificationBell {...leer} unreadCount={0} />);

        expect(screen.queryByText('0')).toBeNull();
    });

    it('deckelt grosse Zahlen', () => {
        render(<NotificationBell {...leer} unreadCount={1234} />);

        expect(screen.getByText('99+')).toBeDefined();
    });

    it('nennt die Zahl auch fuer Screenreader', () => {
        // Ein Abzeichen, das nur farblich auffaellt, sagt einem
        // Screenreader nichts.
        render(<NotificationBell {...leer} unreadCount={3} />);

        expect(screen.getByLabelText('Benachrichtigungen, 3 ungelesen')).toBeDefined();
    });
});

describe('Aufklappen', () => {
    it('zeigt die Liste erst nach einem Klick', () => {
        render(<NotificationBell {...leer} items={[meldung()]} unreadCount={1} />);

        expect(screen.queryByText('Du wurdest erwähnt')).toBeNull();

        fireEvent.click(screen.getByLabelText(/Benachrichtigungen/));

        expect(screen.getByText('Du wurdest erwähnt')).toBeDefined();
    });

    it('schliesst bei einem Klick daneben', () => {
        render(<NotificationBell {...leer} items={[meldung()]} unreadCount={1} />);

        fireEvent.click(screen.getByLabelText(/Benachrichtigungen/));
        expect(screen.getByText('Du wurdest erwähnt')).toBeDefined();

        fireEvent.mouseDown(document.body);

        expect(screen.queryByText('Du wurdest erwähnt')).toBeNull();
    });

    it('schliesst mit Escape', () => {
        render(<NotificationBell {...leer} items={[meldung()]} unreadCount={1} />);

        fireEvent.click(screen.getByLabelText(/Benachrichtigungen/));
        fireEvent.keyDown(document, { key: 'Escape' });

        expect(screen.queryByText('Du wurdest erwähnt')).toBeNull();
    });

    it('schliesst NICHT bei einem Klick in die Liste', () => {
        // Sonst klappt die Klappe beim ersten „gelesen" zu und man muss sie
        // fuer jede weitere Meldung neu oeffnen.
        render(<NotificationBell {...leer} items={[meldung()]} unreadCount={1} />);

        fireEvent.click(screen.getByLabelText(/Benachrichtigungen/));
        fireEvent.mouseDown(screen.getByText('Du wurdest erwähnt'));

        expect(screen.getByText('Du wurdest erwähnt')).toBeDefined();
    });
});

describe('Dieselbe Liste wie im Posteingang', () => {
    it('zeigt Buendelzaehler und Zusatzknoepfe genauso', () => {
        // Der Kern der Sache: Die Glocke hat KEINE eigene Darstellung. Im
        // Manager hatte sie eine, kannte 15 der 39 Arten, und eine
        // Mail-Warnung sah aus wie eine Erinnerung.
        render(
            <NotificationBell
                {...leer}
                items={[meldung({ bundleCount: 4, ids: [1, 2, 3, 4] })]}
                unreadCount={4}
                renderActions={() => <button title="Zurückrufen">x</button>}
            />,
        );

        fireEvent.click(screen.getByLabelText(/Benachrichtigungen/));

        expect(screen.getByText('4×')).toBeDefined();
        expect(screen.getByTitle('Zurückrufen')).toBeDefined();
    });

    it('nimmt einen Fuss auf, etwa den Link zur vollen Uebersicht', () => {
        render(<NotificationBell {...leer} unreadCount={0} footer={<a href="/notifications">Alle ansehen</a>} />);

        fireEvent.click(screen.getByLabelText(/Benachrichtigungen/));

        expect(screen.getByText('Alle ansehen')).toBeDefined();
    });
});
