// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { NotificationSettings, type PreferenceRow } from '../src/NotificationSettings';

afterEach(cleanup);

const WEGE = [
    { key: 'push', label: 'Push' },
    { key: 'inapp', label: 'In der Anwendung' },
    { key: 'email', label: 'E-Mail' },
];

const STUFEN = [
    { key: 'instant', label: 'Sofort' },
    { key: 'digest', label: 'Zusammenfassung' },
    { key: 'off', label: 'Aus' },
];

function zeile(ueberschreiben: Partial<PreferenceRow> = {}): PreferenceRow {
    return {
        type: 'task.assigned',
        label: 'Aufgabe zugewiesen',
        icon: 'user-plus',
        color: 'blue',
        channels: {
            push: { level: 'instant', eigen: false, vorgabe: 'instant' },
            inapp: { level: 'instant', eigen: false, vorgabe: 'instant' },
            email: { level: 'digest', eigen: false, vorgabe: 'digest' },
        },
        ...ueberschreiben,
    };
}

let geschickt: Array<{ url: string; body: unknown }>;

beforeEach(() => {
    geschickt = [];

    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string, init?: RequestInit) => {
            geschickt.push({ url, body: init?.body ? JSON.parse(String(init.body)) : null });

            return new Response(JSON.stringify({ ok: true, types: [zeile()] }), { status: 200 });
        }),
    );
});

function zeige(zeilen: PreferenceRow[] = [zeile()]) {
    return render(<NotificationSettings initial={{ types: zeilen, channels: WEGE, levels: STUFEN }} />);
}

describe('Die Matrix', () => {
    it('zeigt je Weg ein Auswahlfeld', () => {
        zeige();

        expect(screen.getByLabelText('Aufgabe zugewiesen — Push')).toBeDefined();
        expect(screen.getByLabelText('Aufgabe zugewiesen — In der Anwendung')).toBeDefined();
        expect(screen.getByLabelText('Aufgabe zugewiesen — E-Mail')).toBeDefined();
    });

    it('sagt bei jedem Feld ohne eigene Entscheidung „Vorgabe"', () => {
        // Sonst sieht ein Wert, der nur gilt, aus wie einer, der gewaehlt
        // wurde — und eine Aenderung der Vorgabe waere eine Ueberraschung.
        zeige();

        expect(screen.getAllByText('Vorgabe')).toHaveLength(3);
    });

    it('laesst die Vorgabe-Markierung weg, wo selbst entschieden wurde', () => {
        zeige([
            zeile({
                channels: {
                    push: { level: 'off', eigen: true, vorgabe: 'instant' },
                    inapp: { level: 'instant', eigen: false, vorgabe: 'instant' },
                    email: { level: 'digest', eigen: false, vorgabe: 'digest' },
                },
            }),
        ]);

        expect(screen.getAllByText('Vorgabe')).toHaveLength(2);
    });

    it('sagt es, wenn das System keine Arten anmeldet', () => {
        zeige([]);

        expect(screen.getByText('Dieses System meldet noch keine Benachrichtigungsarten an.')).toBeDefined();
    });
});

describe('Speichern', () => {
    it('schickt die ganze Zeile, nicht nur das geaenderte Feld', async () => {
        // Der Server behandelt die Zeile als Einheit: Eine Zeile, in der
        // alles der Vorgabe folgt, wird geloescht. Einzelne Felder zu
        // schicken hiesse, diese Entscheidung hier nachzubauen.
        zeige();

        fireEvent.change(screen.getByLabelText('Aufgabe zugewiesen — Push'), { target: { value: 'off' } });

        await waitFor(() => expect(geschickt).toHaveLength(1));

        expect(geschickt[0]!.body).toEqual({
            type: 'task.assigned',
            channels: { push: 'off', inapp: null, email: null },
        });
    });

    it('behaelt beim Aendern eines Feldes die uebrigen eigenen Entscheidungen', async () => {
        zeige([
            zeile({
                channels: {
                    push: { level: 'instant', eigen: false, vorgabe: 'instant' },
                    inapp: { level: 'instant', eigen: false, vorgabe: 'instant' },
                    email: { level: 'off', eigen: true, vorgabe: 'digest' },
                },
            }),
        ]);

        fireEvent.change(screen.getByLabelText('Aufgabe zugewiesen — Push'), { target: { value: 'off' } });

        await waitFor(() => expect(geschickt).toHaveLength(1));

        expect((geschickt[0]!.body as { channels: Record<string, unknown> }).channels.email).toBe('off');
    });

    it('zeigt den Zuruecksetz-Knopf nur, wo es etwas zurueckzusetzen gibt', () => {
        // Zwei getrennte Darstellungen statt eines Neu-Zeichnens: `initial`
        // ist die Vorbefuellung des ERSTEN Durchlaufs und loest bewusst kein
        // Nachladen aus. Ein `rerender` traefe also gar nichts.
        zeige();

        expect(screen.queryByTitle('Auf die Vorgabe zurücksetzen')).toBeNull();

        cleanup();

        zeige([
            zeile({
                channels: {
                    push: { level: 'off', eigen: true, vorgabe: 'instant' },
                    inapp: { level: 'instant', eigen: false, vorgabe: 'instant' },
                    email: { level: 'digest', eigen: false, vorgabe: 'digest' },
                },
            }),
        ]);

        expect(screen.getByTitle('Auf die Vorgabe zurücksetzen')).toBeDefined();
    });

    it('setzt die ganze Zeile auf „folgt der Vorgabe" zurueck', async () => {
        zeige([
            zeile({
                channels: {
                    push: { level: 'off', eigen: true, vorgabe: 'instant' },
                    inapp: { level: 'off', eigen: true, vorgabe: 'instant' },
                    email: { level: 'instant', eigen: true, vorgabe: 'digest' },
                },
            }),
        ]);

        fireEvent.click(screen.getByTitle('Auf die Vorgabe zurücksetzen'));

        await waitFor(() => expect(geschickt).toHaveLength(1));

        // Alle drei leer — nicht „auf den heutigen Vorgabewert gestellt".
        expect((geschickt[0]!.body as { channels: Record<string, unknown> }).channels)
            .toEqual({ push: null, inapp: null, email: null });
    });

    it('sagt es, wenn das Speichern scheitert', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => new Response('{}', { status: 500 })));

        zeige();

        fireEvent.change(screen.getByLabelText('Aufgabe zugewiesen — Push'), { target: { value: 'off' } });

        await waitFor(() =>
            expect(screen.getByText('Gespeichert wurde nichts — die Antwort war nicht in Ordnung.')).toBeDefined(),
        );
    });
});
