// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { NotificationList } from '../src/NotificationList';
import type { NotificationItem } from '../src/types';

afterEach(cleanup);

function meldung(ueberschreiben: Partial<NotificationItem> = {}): NotificationItem {
    return {
        id: 1,
        ids: [1],
        bundleCount: 1,
        type: 'task.assigned',
        typeLabel: 'Aufgabe zugewiesen',
        title: 'Aufgabe zugewiesen',
        message: 'Du wurdest zugewiesen.',
        icon: 'user-plus',
        color: 'blue',
        link: '/tasks/1',
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

const leer = { onResolve: vi.fn(), onDelete: vi.fn() };

describe('Grundform', () => {
    it('zeigt Titel, Nachricht und Art', () => {
        render(<NotificationList items={[meldung()]} {...leer} />);

        expect(screen.getByText('Aufgabe zugewiesen')).toBeDefined();
        expect(screen.getByText('Du wurdest zugewiesen.')).toBeDefined();
    });

    it('zeigt den Leerzustand ohne Meldungen', () => {
        render(<NotificationList items={[]} {...leer} emptyLabel="Nichts da" />);

        expect(screen.getByText('Nichts da')).toBeDefined();
    });

    it('trennt Neu von Frueher', () => {
        render(
            <NotificationList
                items={[meldung({ id: 1, ids: [1], title: 'Frisch' }), meldung({ id: 2, ids: [2], title: 'Alt', isRead: true })]}
                {...leer}
            />,
        );

        expect(screen.getByText('Neu (1)')).toBeDefined();
        expect(screen.getByText('Früher (1)')).toBeDefined();
    });
});

describe('Buendelung', () => {
    it('zeigt den Zaehler, wenn mehrere Meldungen dahinterstecken', () => {
        render(<NotificationList items={[meldung({ bundleCount: 7, ids: [1, 2, 3, 4, 5, 6, 7] })]} {...leer} />);

        expect(screen.getByText('7×')).toBeDefined();
    });

    it('zeigt KEINEN Zaehler bei einer einzelnen Meldung', () => {
        // „1×" an jeder Zeile ist Rauschen.
        render(<NotificationList items={[meldung()]} {...leer} />);

        expect(screen.queryByText('1×')).toBeNull();
    });

    it('gibt beim Loeschen ALLE Kennungen der Zeile weiter', () => {
        // Sonst bleibt die Zeile stehen und der Zaehler ist falsch.
        const onDelete = vi.fn();
        render(<NotificationList items={[meldung({ bundleCount: 3, ids: [1, 2, 3] })]} onResolve={vi.fn()} onDelete={onDelete} />);

        fireEvent.click(screen.getByTitle('Löschen'));

        expect(onDelete).toHaveBeenCalledWith([1, 2, 3]);
    });
});

describe('Entdoppelung', () => {
    it('blendet aus, was anderswo schon steht — und sagt wie viel', () => {
        render(
            <NotificationList
                items={[meldung({ id: 1, ids: [1], title: 'Sichtbar' }), meldung({ id: 2, ids: [2], title: 'Doppelt' })]}
                isDuplicate={(item) => item.title === 'Doppelt'}
                {...leer}
            />,
        );

        expect(screen.queryByText('Doppelt')).toBeNull();
        expect(screen.getByText(/1 Meldung zu Dingen, die anderswo schon stehen/)).toBeDefined();
    });

    it('holt das Ausgeblendete auf Klick zurueck — ausgeblendet, nicht weggeworfen', () => {
        render(
            <NotificationList
                items={[meldung({ id: 1, ids: [1], title: 'Sichtbar' }), meldung({ id: 2, ids: [2], title: 'Doppelt' })]}
                isDuplicate={(item) => item.title === 'Doppelt'}
                {...leer}
            />,
        );

        fireEvent.click(screen.getByText('Doppelungen zeigen'));

        expect(screen.getByText('Doppelt')).toBeDefined();
        expect(screen.getByText('steht auch anderswo')).toBeDefined();
    });

    it('blendet nichts aus, wenn das Produkt keine Regel mitgibt', () => {
        render(<NotificationList items={[meldung(), meldung({ id: 2, ids: [2], title: 'Zweite' })]} {...leer} />);

        expect(screen.getByText('Zweite')).toBeDefined();
        expect(screen.queryByText(/Doppelungen/)).toBeNull();
    });
});

describe('Aktionen', () => {
    it('bietet "gelesen" nur bei Ungelesenem an', () => {
        const { rerender } = render(<NotificationList items={[meldung({ isRead: false })]} {...leer} />);
        expect(screen.queryByTitle('Als gelesen markieren')).not.toBeNull();

        rerender(<NotificationList items={[meldung({ isRead: true })]} {...leer} />);
        expect(screen.queryByTitle('Als gelesen markieren')).toBeNull();
    });

    it('zeigt Sammelaktionen nur, wenn es etwas zu tun gibt', () => {
        const { rerender } = render(<NotificationList items={[]} {...leer} onMarkAllRead={vi.fn()} onDeleteAll={vi.fn()} />);
        expect(screen.queryByText('Alle gelesen')).toBeNull();

        rerender(<NotificationList items={[meldung()]} {...leer} onMarkAllRead={vi.fn()} onDeleteAll={vi.fn()} />);
        expect(screen.getByText('Alle gelesen')).toBeDefined();
    });

    it('nimmt zusaetzliche Knoepfe des Produkts auf', () => {
        // Im Manager etwa der Rueckruf bei einem verpassten Anruf. Ohne
        // diesen Haken muesste jeder Sonderfall ins Paket.
        render(
            <NotificationList
                items={[meldung()]}
                {...leer}
                renderActions={(item) => <button title="Zurückrufen">{item.type}</button>}
            />,
        );

        expect(screen.getByTitle('Zurückrufen')).toBeDefined();
    });

    it('nimmt Zusatzzeilen des Produkts auf', () => {
        render(
            <NotificationList items={[meldung()]} {...leer} renderDetails={() => <p>Projekt Alpha</p>} />,
        );

        expect(screen.getByText('Projekt Alpha')).toBeDefined();
    });
});

describe('Oeffnen', () => {
    it('ueberlaesst dem Produkt die Navigation, wenn es eine mitgibt', () => {
        // Die vier Produkte sind Inertia-Anwendungen; ein `window.location`
        // aus dem Paket heraus wuerde dort die ganze Seite neu laden.
        const onOpen = vi.fn();
        render(<NotificationList items={[meldung()]} {...leer} onOpen={onOpen} />);

        fireEvent.click(screen.getByTitle('Öffnen'));

        expect(onOpen).toHaveBeenCalledOnce();
    });

    it('zeigt den Oeffnen-Knopf nicht ohne Ziel', () => {
        render(<NotificationList items={[meldung({ link: null })]} {...leer} />);

        expect(screen.queryByTitle('Öffnen')).toBeNull();
    });

    it('verbucht beim Oeffnen zuerst als gesehen, dann wird navigiert', () => {
        // Umgekehrt ist die Seite weg, bevor die Quittung raus ist.
        const reihenfolge: string[] = [];
        const onResolve = vi.fn(() => void reihenfolge.push('gelesen'));
        const onOpen = vi.fn(() => void reihenfolge.push('navigiert'));

        render(
            <NotificationList
                items={[meldung()]}
                onResolve={onResolve}
                onDelete={vi.fn()}
                onOpen={(item) => {
                    if (!item.isRead) {
                        onResolve();
                    }
                    onOpen();
                }}
            />,
        );

        fireEvent.click(screen.getByTitle('Öffnen'));

        expect(reihenfolge).toEqual(['gelesen', 'navigiert']);
    });
});

describe('Zeitangabe', () => {
    it('ueberlaesst dem Produkt die Formatierung', () => {
        render(<NotificationList items={[meldung()]} {...leer} formatTime={() => 'vor 2 Stunden'} />);

        expect(screen.getByText('vor 2 Stunden')).toBeDefined();
    });
});
