import { describe, expect, it } from 'vitest';
import {
    bekannteFlaechenfarben,
    bekannteFarben,
    bekannteSymbole,
    notificationIcon,
    notificationSurfaceClass,
    notificationTextClass,
} from '../src/icons';

describe('Symbole', () => {
    it('liefert zu einem bekannten Namen ein Symbol', () => {
        expect(notificationIcon('user-plus')).toBeTypeOf('object');
        expect(notificationIcon('user-plus')).not.toBe(notificationIcon('mail'));
    });

    it('faellt bei einem unbekannten Namen auf die Glocke zurueck statt zu scheitern', () => {
        // Eine Benachrichtigung ohne passendes Symbol ist besser als keine.
        expect(notificationIcon('gibt-es-nicht')).toBe(notificationIcon('bell'));
    });
});

describe('Farben', () => {
    it('hat zu jedem Farbwort eine Schrift- UND eine Flaechenklasse', () => {
        // Fehlt einer Farbe eine der beiden Haelften, faellt sie still auf
        // Grau zurueck und die Zeile sieht halb eingefaerbt aus. Das faellt
        // erst auf, wenn eine Meldung dieser Farbe auftritt — bei einer
        // seltenen Art also womoeglich nie.
        //
        // Der Vergleich geht ueber die SCHLUESSEL, nicht ueber den
        // Rueckfall-Wert: Fuer Grau sind Rueckfall und echter Wert dieselben,
        // und ein Test gegen den Rueckfall faellt genau dort faelschlich.
        expect(bekannteFlaechenfarben().sort()).toEqual(bekannteFarben().sort());
    });

    it('faellt bei einem unbekannten Farbwort auf grau zurueck', () => {
        expect(notificationTextClass('magenta')).toBe(notificationTextClass('gray'));
        expect(notificationSurfaceClass('magenta')).toBe(notificationSurfaceClass('gray'));
    });

    it('schreibt die Klassennamen aus, statt sie zusammenzusetzen', () => {
        // Tailwind scannt den Quelltext nach Zeichenketten. Ein
        // zusammengesetztes `text-${color}-600` landet nie im Build, und die
        // Farbe fehlt stumm. Der Test haelt fest, dass vollstaendige
        // Klassennamen herauskommen.
        expect(notificationTextClass('blue')).toContain('text-blue-600');
        expect(notificationTextClass('blue')).toContain('dark:text-blue-400');
        expect(notificationSurfaceClass('red')).toContain('bg-red-50');
        expect(notificationSurfaceClass('red')).toContain('dark:bg-red-950');
    });
});

describe('Vollstaendigkeit', () => {
    it('kennt die Symbole, die die Produkte heute tatsaechlich schicken', () => {
        // Der Fehler, gegen den das steht: Im Manager lieferte der Server 39
        // Symbolnamen, die Glocke kannte 15 — eine Mail-Warnung sah aus wie
        // eine Erinnerung. Die Liste hier ist der gemessene Bestand aus
        // NotificationType::icon() (Manager, 16.10.2026).
        const ausDemManager = [
            'user-plus', 'user-minus', 'message-circle', 'refresh-cw', 'clock',
            'alert-triangle', 'calendar', 'lock', 'unlock', 'trash-2', 'mail',
            'folder-plus', 'folder-minus', 'folder-cog', 'folder-x', 'bell',
            'at-sign', 'thermometer', 'calendar-check', 'calendar-x',
            'calendar-clock', 'book-open', 'alarm-clock', 'megaphone',
            'calendar-plus', 'phone-missed', 'inbox', 'check-circle',
            'x-circle', 'package-check', 'mail-warning',
        ];

        const fehlend = ausDemManager.filter((name) => !bekannteSymbole().includes(name));

        expect(fehlend, `Diese Symbole liefert der Manager, das Paket kennt sie nicht: ${fehlend.join(', ')}`).toEqual([]);
    });
});
