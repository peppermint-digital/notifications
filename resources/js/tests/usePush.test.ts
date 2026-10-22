import { afterEach, describe, expect, it, vi } from 'vitest';
import { gleicherSchluessel, grundFuer, oeffentlicherSchluessel } from '../src/usePush';

function abo(schluessel: ArrayBuffer | null): PushSubscription {
    return { options: schluessel === null ? {} : { applicationServerKey: schluessel } } as unknown as PushSubscription;
}

const A = new Uint8Array([1, 2, 3]).buffer;
const B = new Uint8Array([9, 9, 9]).buffer;

describe('Ein altes Abo erkennen', () => {
    it('erkennt denselben Schluessel', () => {
        // Ohne diese Richtung würde ein Code, der IMMER abmeldet, genauso
        // grün aussehen — und bei jedem Versuch das funktionierende Abo
        // wegwerfen.
        expect(gleicherSchluessel(abo(A), new Uint8Array([1, 2, 3]).buffer)).toBe(true);
    });

    it('erkennt einen anderen Schluessel', () => {
        // Der Fall, der den Fehler verursacht hat: Der Browser lässt pro
        // Registrierung ein Abo zu; ein fremder Schlüssel darin lässt
        // `subscribe()` jedes Mal mit InvalidStateError scheitern.
        expect(gleicherSchluessel(abo(A), B)).toBe(false);
    });

    it('erkennt verschiedene Laengen', () => {
        expect(gleicherSchluessel(abo(A), new Uint8Array([1, 2]).buffer)).toBe(false);
    });

    it('behandelt ein Abo ohne options als fremd', () => {
        // Lieber einmal zu viel neu abonnieren als dauerhaft an einem
        // alten hängen, das man nicht prüfen kann.
        expect(gleicherSchluessel(abo(null), A)).toBe(false);
    });
});

describe('Der Grund steht im Fehlertext', () => {
    it('nennt die gesperrte Erlaubnis', () => {
        const f = new Error('x');
        f.name = 'NotAllowedError';

        expect(grundFuer(f)).toContain('gesperrt');
    });

    it('nennt das alte Abo', () => {
        const f = new Error('x');
        f.name = 'InvalidStateError';

        expect(grundFuer(f)).toContain('altes Abo');
    });

    it('nennt das iPhone beim nicht unterstuetzten Browser', () => {
        const f = new Error('x');
        f.name = 'NotSupportedError';

        expect(grundFuer(f)).toContain('Home-Bildschirm');
    });

    it('bleibt bei Unbekanntem allgemein, verweist aber auf die Konsole', () => {
        // „Hat nicht geklappt" allein schickt den Menschen zum Support
        // und den Support in die Entwicklerkonsole — ohne zu sagen, dass
        // sie dort etwas findet.
        expect(grundFuer(new Error('irgendwas'))).toContain('konsole');
    });
});

/**
 * Drei Faelle, nicht zwei (Bug #1068).
 *
 * Vorher gab diese Funktion `string | null` zurueck, und damit war „der
 * Server kennt keinen Schluessel" nicht von „du bist nicht mehr angemeldet"
 * zu unterscheiden. Die Oberflaeche zeigte fuer beides denselben Satz — und
 * der nannte die falsche Ursache.
 */
describe('Den Schluessel holen', () => {
    const mitAntwort = (antwort: Response) => {
        vi.stubGlobal('fetch', vi.fn(async () => antwort));
    };

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('gibt den Schluessel heraus, wenn er da ist', async () => {
        mitAntwort(new Response(JSON.stringify({ public_key: 'ABC' }), { status: 200 }));

        await expect(oeffentlicherSchluessel('/x')).resolves.toEqual({ art: 'da', schluessel: 'ABC' });
    });

    it('nennt 401 als fehlende Anmeldung', async () => {
        mitAntwort(new Response(JSON.stringify({ message: 'Unauthenticated.' }), { status: 401 }));

        await expect(oeffentlicherSchluessel('/x')).resolves.toEqual({ art: 'nicht-angemeldet' });
    });

    it('nennt auch 419 als fehlende Anmeldung — der abgelaufene CSRF-Token', async () => {
        // Fuer den Menschen dasselbe: neu anmelden.
        mitAntwort(new Response('', { status: 419 }));

        await expect(oeffentlicherSchluessel('/x')).resolves.toEqual({ art: 'nicht-angemeldet' });
    });

    it('bleibt bei einem Serverfehler bei „keiner"', async () => {
        mitAntwort(new Response('', { status: 503 }));

        await expect(oeffentlicherSchluessel('/x')).resolves.toEqual({ art: 'keiner' });
    });

    it('behandelt eine Antwort ohne Schluessel als „keiner"', async () => {
        mitAntwort(new Response(JSON.stringify({ public_key: null }), { status: 200 }));

        await expect(oeffentlicherSchluessel('/x')).resolves.toEqual({ art: 'keiner' });
    });

    it('behandelt ein totes Netz als „keiner" — ueber die Anmeldung ist dann nichts bekannt', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => { throw new Error('offline'); }));

        await expect(oeffentlicherSchluessel('/x')).resolves.toEqual({ art: 'keiner' });
    });
});
