// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { NotificationPage } from '../src/NotificationPage';
import type { NotificationFeed, NotificationItem } from '../src/types';

afterEach(cleanup);

beforeEach(() => {
    // Die Seite laedt beim Aufsetzen nach. Ohne Attrappe schlaegt `fetch` in
    // jsdom fehl und jeder Test liefe in eine unbehandelte Ablehnung.
    vi.stubGlobal('fetch', vi.fn(async () => new Response('{"items":[],"unread_count":0}', { status: 200 })));
});

function meldung(ueberschreiben: Partial<NotificationItem> = {}): NotificationItem {
    return {
        id: 1,
        ids: [1],
        bundleCount: 1,
        type: 'task.assigned',
        typeLabel: 'Aufgabe zugewiesen',
        title: 'Aufgabe zugewiesen',
        message: null,
        icon: 'user-plus',
        color: 'blue',
        link: null,
        isRead: false,
        createdAt: '2026-10-16T10:00:00+00:00',
        source: null,
        ...ueberschreiben,
    };
}

function feed(items: NotificationItem[]): NotificationFeed {
    return { items, unreadCount: items.filter((i) => !i.isRead).length };
}

/**
 * Den Filter-REITER mit diesem Namen klicken.
 *
 * Gezielt ueber die Knopfrolle: Seit die Herkunft auch an der Zeile steht,
 * findet `getByText('Manager')` zwei Stellen und wirft. Der Reiter ist die,
 * die man klicken kann.
 */
function reiter(name: string): HTMLElement {
    const treffer = screen
        .getAllByRole('button')
        .filter((knopf) => knopf.textContent?.startsWith(name));

    expect(treffer).toHaveLength(1);

    return treffer[0]!;
}

/** Die Seite ohne Nachladen — jeder Test setzt seinen Stand selbst. */
function zeige(items: NotificationItem[], props: Record<string, unknown> = {}) {
    return render(<NotificationPage initial={feed(items)} pollMs={0} {...props} />);
}

describe('Kopfzeile', () => {
    it('zaehlt ungelesene Meldungen, nicht Zeilen', () => {
        zeige([meldung({ id: 1 }), meldung({ id: 2, isRead: true })]);

        expect(screen.getByText('1 ungelesen von 2 Zeilen')).toBeDefined();
    });

    it('sagt es anders, wenn alles gelesen ist', () => {
        zeige([meldung({ isRead: true })]);

        expect(screen.getByText('1 Zeile, alles gelesen')).toBeDefined();
    });
});

describe('Filter nach Herkunft', () => {
    it('zeigt keine Herkunfts-Reiter, wenn alles von hier kommt', () => {
        zeige([meldung(), meldung({ id: 2 })]);

        expect(screen.queryByText('Alle Systeme')).toBeNull();
    });

    it('zeigt einen Reiter je vorkommender Herkunft, mit Anzahl', () => {
        zeige([meldung(), meldung({ id: 2, source: 'peppermint-manager' })], {
            sourceLabels: { 'peppermint-manager': 'Manager' },
            localLabel: 'CRM',
        });

        expect(screen.getByText('Alle Systeme')).toBeDefined();
        expect(reiter('CRM').textContent).toBe('CRM1');
        expect(reiter('Manager').textContent).toBe('Manager1');
    });

    it('blendet beim Filtern aus, was aus einem anderen System stammt', () => {
        zeige([meldung({ title: 'Von hier' }), meldung({ id: 2, title: 'Von drueben', source: 'peppermint-manager' })], {
            sourceLabels: { 'peppermint-manager': 'Manager' },
        });

        fireEvent.click(reiter('Manager'));

        expect(screen.queryByText('Von hier')).toBeNull();
        expect(screen.getByText('Von drueben')).toBeDefined();
    });

    it('nimmt den Filter beim zweiten Klick auf denselben Reiter zurueck', () => {
        zeige([meldung({ title: 'Von hier' }), meldung({ id: 2, source: 'peppermint-manager' })], {
            sourceLabels: { 'peppermint-manager': 'Manager' },
        });

        fireEvent.click(reiter('Manager'));
        fireEvent.click(reiter('Manager'));

        expect(screen.getByText('Von hier')).toBeDefined();
    });

    it('benennt eine unbekannte Herkunft mit ihrem Kuerzel statt sie zu verschlucken', () => {
        // Ein neues Produkt im Verbund darf nicht dazu fuehren, dass seine
        // Meldungen in keinem Reiter erreichbar sind.
        zeige([meldung({ source: 'neues-produkt' })]);

        expect(screen.getByText('neues-produkt')).toBeDefined();
    });
});

describe('Filter nach Lesestand', () => {
    it('zeigt nur Ungelesene, wenn der Filter an ist', () => {
        zeige([meldung({ title: 'Neu' }), meldung({ id: 2, title: 'Alt', isRead: true })]);

        fireEvent.click(screen.getByText('Nur ungelesen'));

        expect(screen.getByText('Neu')).toBeDefined();
        expect(screen.queryByText('Alt')).toBeNull();
    });

    it('sagt beim leeren Ergebnis, dass der Filter schuld ist', () => {
        // Sonst liest sich ein strenger Filter wie ein leerer Posteingang.
        zeige([meldung({ isRead: true })]);

        fireEvent.click(screen.getByText('Nur ungelesen'));

        expect(screen.getByText('Nichts, das zu diesem Filter passt')).toBeDefined();
    });
});

describe('Herkunft an der Zeile', () => {
    it('schreibt das Wort an die Zeile, nicht das Kuerzel', () => {
        zeige([meldung(), meldung({ id: 2, source: 'peppermint-manager' })], {
            sourceLabels: { 'peppermint-manager': 'Manager' },
        });

        // Zweimal: einmal als Reiter, einmal als Abzeichen an der Zeile. Nur
        // der Reiter waere zu wenig — beim Blick auf eine einzelne Zeile muss
        // erkennbar sein, wo die Sache passiert ist.
        expect(screen.getAllByText('Manager')).toHaveLength(2);
    });

    it('schreibt an eine eigene Zeile gar nichts', () => {
        zeige([meldung()], { localLabel: 'CRM' });

        // „von hier" ist keine Information, solange alles von hier kommt.
        expect(screen.queryByText('CRM')).toBeNull();
    });
});

describe('Fremde Zeilen', () => {
    it('bietet bei einer Zeile aus einem anderen System kein „gelesen" an', () => {
        // Sie liegt drueben. Ein Haken hier koennte sie dort nicht erreichen
        // — und genau das war der Fehler der kopierten Fassung: Man markierte
        // eine Kopie als gelesen, das Original blieb ungelesen.
        zeige([meldung({ source: 'peppermint-manager' })], {
            sourceLabels: { 'peppermint-manager': 'Manager' },
        });

        expect(screen.queryByTitle('Als gelesen markieren')).toBeNull();
        expect(screen.queryByTitle('Löschen')).toBeNull();
    });

    it('bietet es bei einer eigenen Zeile weiterhin an', () => {
        zeige([meldung()]);

        expect(screen.getByTitle('Als gelesen markieren')).toBeDefined();
        expect(screen.getByTitle('Löschen')).toBeDefined();
    });

    it('laesst den Verweis stehen, er fuehrt in das andere System', () => {
        zeige([meldung({ source: 'peppermint-manager', link: 'https://pm.test/tasks/5' })], {
            sourceLabels: { 'peppermint-manager': 'Manager' },
        });

        expect(screen.getByTitle('Öffnen')).toBeDefined();
    });
});

describe('Stumme Systeme', () => {
    it('sagt es, wenn ein System nicht geantwortet hat', () => {
        // Eine still verkuerzte Liste sieht aus wie „da ist nichts".
        zeige([meldung()], { unreachable: ['Verwaltung'] });

        expect(screen.getByText(/Verwaltung antwortet gerade nicht/)).toBeDefined();
    });

    it('nennt mehrere zusammen', () => {
        zeige([meldung()], { unreachable: ['CRM', 'Verwaltung'] });

        expect(screen.getByText(/CRM, Verwaltung antworten gerade nicht/)).toBeDefined();
    });

    it('schweigt, wenn alle geantwortet haben', () => {
        zeige([meldung()]);

        expect(screen.queryByText(/antwortet gerade nicht/)).toBeNull();
    });
});
