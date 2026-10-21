// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { NotificationSettingsMoved } from '../src/NotificationSettingsMoved';

afterEach(() => {
    cleanup();
});

describe('NotificationSettingsMoved', () => {
    it('zeigt den Weg ins Brain mit der Adresse des Produkts', () => {
        render(<NotificationSettingsMoved href="https://brain.beispiel.test/settings/notifications" />);

        const verweis = screen.getByRole('link', { name: /In AI Brain einstellen/ });

        expect(verweis.getAttribute('href')).toBe('https://brain.beispiel.test/settings/notifications');
    });

    it('nennt das System, wenn das Produkt seinen Namen mitgibt', () => {
        render(<NotificationSettingsMoved href="/x" systemName="Peppermint Manager" />);

        expect(screen.getByText(/Arten von Peppermint Manager/)).toBeTruthy();
    });

    it('kommt ohne Namen zurecht', () => {
        render(<NotificationSettingsMoved href="/x" />);

        expect(screen.getByText(/Arten dieses Systems/)).toBeTruthy();
    });

    /**
     * Kein Weiterleiten: Eine Seite, die von selbst in ein anderes System
     * springt, nimmt dem Menschen die Entscheidung ab und wirft ihn aus
     * seinem Arbeitsfluss. Festgeschrieben, weil „dann leiten wir doch gleich
     * weiter" eine naheliegende spätere Idee ist.
     */
    it('leitet nicht von selbst weiter', () => {
        const { container } = render(<NotificationSettingsMoved href="/x" />);

        expect(container.querySelector('meta[http-equiv="refresh"]')).toBeNull();
        expect(container.querySelector('script')).toBeNull();
    });
});
