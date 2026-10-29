/** @vitest-environment jsdom */

import { afterEach, describe, expect, it, vi } from 'vitest';
import { oeffneVerweis } from '../src/verweis';

/**
 * Wohin ein Klick geht (AI Brain #1151).
 *
 * jsdom laeuft auf `http://localhost`. Die Verweise hier sind die, die am
 * 29.10.2026 in Produktion gemessen wurden.
 */
function mitAssign(): ReturnType<typeof vi.fn> {
    const assign = vi.fn();

    // `window.location.assign` ist in jsdom nicht aufrufbar; ersetzen statt
    // aufrufen, sonst meldet jsdom „Not implemented: navigation".
    Object.defineProperty(window, 'location', {
        configurable: true,
        value: { ...window.location, assign, href: 'http://localhost/posteingang', origin: 'http://localhost' },
    });

    return assign;
}

afterEach(() => vi.restoreAllMocks());

describe('oeffneVerweis', () => {
    it('schickt einen Verweis in ein ANDERES System an den Browser', () => {
        // Der Router wuerde ihn als Seite DIESER Anwendung anfordern — genau
        // der Fehler, den die Glocke bis zum 29.10.2026 gemacht haette,
        // sobald fremde Zeilen eine absolute Adresse tragen.
        const assign = mitAssign();
        const navigieren = vi.fn();

        oeffneVerweis('https://brain.proxy.peppermint-digital.com/bugs/1122', navigieren);

        expect(assign).toHaveBeenCalledWith('https://brain.proxy.peppermint-digital.com/bugs/1122');
        expect(navigieren).not.toHaveBeenCalled();
    });

    it('laesst einen relativen Verweis im Router', () => {
        const assign = mitAssign();
        const navigieren = vi.fn();

        oeffneVerweis('/bugs/1122', navigieren);

        expect(navigieren).toHaveBeenCalledWith('/bugs/1122');
        expect(assign).not.toHaveBeenCalled();
    });

    it('laesst einen ABSOLUTEN Verweis auf den eigenen Ursprung im Router', () => {
        /*
         * Der Fall, den eine Pruefung auf `/^https?:\/\//` falsch einordnet —
         * und mehrere Produkte bauen ihre Verweise seit immer absolut
         * (`config('app.url')`). Ein Klick auf eine EIGENE Zeile soll den
         * Zustand der Anwendung nicht wegwerfen.
         */
        const assign = mitAssign();
        const navigieren = vi.fn();

        oeffneVerweis('http://localhost/tasks/42', navigieren);

        expect(navigieren).toHaveBeenCalledWith('http://localhost/tasks/42');
        expect(assign).not.toHaveBeenCalled();
    });

    it('schickt ein anderes Schema an den Browser', () => {
        // `mailto:` ist nicht einmal eine Seite.
        const assign = mitAssign();
        const navigieren = vi.fn();

        oeffneVerweis('mailto:hallo@peppermint-digital.com', navigieren);

        expect(assign).toHaveBeenCalledWith('mailto:hallo@peppermint-digital.com');
        expect(navigieren).not.toHaveBeenCalled();
    });

    it('tut nichts, wo es nichts zu oeffnen gibt', () => {
        // Die 59 imkr-Zeilen aus der Messung: Sie gehoeren zu einem Pane auf
        // einem Rechner und zu keiner Seite.
        const assign = mitAssign();
        const navigieren = vi.fn();

        for (const nichts of [null, undefined, '', '   ']) {
            oeffneVerweis(nichts, navigieren);
        }

        expect(navigieren).not.toHaveBeenCalled();
        expect(assign).not.toHaveBeenCalled();
    });

    it('nimmt ohne Router den Browser', () => {
        // Ein Paket darf nicht voraussetzen, dass die Anwendung eine SPA ist.
        // Ein Klick, der nichts tut, waere der schlechteste Ausgang.
        const assign = mitAssign();

        oeffneVerweis('/bugs/1122');

        expect(assign).toHaveBeenCalledWith('/bugs/1122');
    });
});
