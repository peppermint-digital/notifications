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

    /**
     * Der Hinweis muss den GERÄTE-Schalter mitnennen, nicht nur die Matrix.
     *
     * Bis zum 22.10.2026 stand der Schalter in den Produkten, der Satz sprach
     * also zu Recht nur von den „Arten". Seit er dort weg ist, wäre genau
     * dieser Satz eine Falle: Wer Push einschalten will, liest „die Arten
     * stehen im Brain", sucht hier weiter nach dem Einschalter und findet
     * nichts. Das war drei Tage lang der Zustand von #1068.
     *
     * Geprüft wird auf „Gerät" und nicht auf den ganzen Satz — der darf
     * umformuliert werden, das Thema nicht wegfallen.
     */
    it('nennt auch, dass das Gerät im Brain freigeschaltet wird', () => {
        render(<NotificationSettingsMoved href="/x" />);

        expect(screen.getByText(/dieses Gerät/)).toBeTruthy();
        expect(screen.getByText(/Push-Abo/)).toBeTruthy();
    });
});
