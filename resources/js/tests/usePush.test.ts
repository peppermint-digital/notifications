import { describe, expect, it } from 'vitest';
import { gleicherSchluessel, grundFuer } from '../src/usePush';

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
