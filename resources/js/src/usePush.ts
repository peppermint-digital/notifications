import { useCallback, useEffect, useState } from 'react';

/**
 * Web-Push im Browser: Zustand, An- und Abmelden.
 *
 * Dieselbe Mechanik in jedem Produkt. Vorher gab es sie zweimal — in AI Brain
 * und im Manager —, in CRM und Verwaltung gar nicht; dort stand in der
 * Einstellungsmaske eine Push-Spalte, die man stellen konnte und die nichts
 * tat.
 *
 * ## Woher der Zustand kommt
 *
 * Aus dem BROWSER, nicht vom Server. Ob dieses Gerät abonniert hat, weiß die
 * Registrierung des Service Workers — und nur sie. Ein Server-Flag wäre eine
 * zweite Wahrheit: Es stünde weiter auf „abonniert", nachdem jemand die
 * Website-Daten gelöscht hat.
 *
 * ## Die Stolperstelle mit dem Schlüssel
 *
 * `applicationServerKey` verlangt einen `BufferSource`, keine Zeichenkette.
 * Der Schlüssel kommt base64url-kodiert über die Leitung und muss von Hand
 * umgerechnet werden — mit `atob` allein geht es nicht, weil base64url `-`
 * und `_` benutzt, wo base64 `+` und `/` hat.
 */

export type PushStatus =
    /** Wird noch festgestellt. */
    | 'pruefe'
    /** Dieser Browser kann kein Web-Push. */
    | 'nicht-moeglich'
    /** Der Mensch hat Benachrichtigungen für diese Seite abgelehnt. */
    | 'abgelehnt'
    /** Es gibt keinen Schlüssel — niemand kann hier abonnieren. */
    | 'kein-schluessel'
    | 'abonniert'
    | 'nicht-abonniert';

export interface PushRoutes {
    /** Vorgabe: `/api/push/public-key` */
    publicKey?: string;
    /** Vorgabe: `/api/push/subscribe` (POST und DELETE) */
    subscribe?: string;
    /** Vorgabe: `/push-sw.js` */
    worker?: string;
}

export interface UsePushResult {
    status: PushStatus;
    busy: boolean;
    error: string | null;
    subscribe: () => Promise<void>;
    unsubscribe: () => Promise<void>;
}

const VORGABE: Required<PushRoutes> = {
    publicKey: '/api/push/public-key',
    subscribe: '/api/push/subscribe',
    worker: '/push-sw.js',
};

/**
 * base64url → Bytes, wie `applicationServerKey` es verlangt.
 *
 * Mit `atob` allein geht es nicht: base64url benutzt `-` und `_`, wo base64
 * `+` und `/` hat, und das Auffüllen mit `=` fehlt.
 *
 * Der Rückgabetyp ist ausdrücklich `ArrayBuffer` und nicht `Uint8Array`: Die
 * Typen des Browsers lassen seit TypeScript 5.7 ein `Uint8Array` nicht mehr
 * als `BufferSource` durchgehen, weil dessen Puffer auch ein
 * `SharedArrayBuffer` sein könnte. Der Puffer selbst passt.
 */
function schluesselBytes(base64url: string): ArrayBuffer {
    const auffuellen = '='.repeat((4 - (base64url.length % 4)) % 4);
    const base64 = (base64url + auffuellen).replace(/-/g, '+').replace(/_/g, '/');
    const roh = window.atob(base64);

    const bytes = new Uint8Array(roh.length);

    for (let i = 0; i < roh.length; i++) {
        bytes[i] = roh.charCodeAt(i);
    }

    return bytes.buffer;
}

function csrfHeaders(): Record<string, string> {
    if (typeof document === 'undefined') {
        return {};
    }

    const meta = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    if (meta) {
        return { 'X-CSRF-TOKEN': meta };
    }

    const cookie = document.cookie
        .split('; ')
        .find((teil) => teil.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length);

    return cookie ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie) } : {};
}

export function usePush(routes?: PushRoutes): UsePushResult {
    const adressen = { ...VORGABE, ...routes };

    const [status, setStatus] = useState<PushStatus>('pruefe');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const moeglich = (): boolean =>
        typeof window !== 'undefined'
        && 'serviceWorker' in navigator
        && 'PushManager' in window
        && 'Notification' in window;

    const pruefen = useCallback(async (): Promise<void> => {
        if (!moeglich()) {
            setStatus('nicht-moeglich');

            return;
        }

        if (Notification.permission === 'denied') {
            setStatus('abgelehnt');

            return;
        }

        try {
            const registrierung = await navigator.serviceWorker.getRegistration();
            const abo = await registrierung?.pushManager.getSubscription();

            if (!abo) {
                setStatus('nicht-abonniert');

                return;
            }

            // Ein Abo im Browser heisst noch nicht, dass es ankommt.
            //
            // Bis v0.22 fragte diese Pruefung nur den Browser. Lag dort
            // ein Abo, von dem der SERVER nichts weiss — weil es mit dem
            // alten Schluessel entstand oder die Zeile geloescht wurde —,
            // stand der Schalter auf „abonniert" und es kam trotzdem nie
            // etwas an. Ein gruener Schalter ohne Wirkung ist schlimmer
            // als ein grauer: Man merkt nicht, dass man nichts bekommt.
            const schluessel = await oeffentlicherSchluessel(adressen.publicKey);

            if (schluessel === null || !gleicherSchluessel(abo, schluesselBytes(schluessel))) {
                // Fremder Schluessel: Das Abo ist tot. Als „nicht
                // abonniert" zeigen, damit der Knopf es ersetzen kann —
                // genau das tut `subscribe()` inzwischen.
                setStatus('nicht-abonniert');

                return;
            }

            // Richtiger Schluessel: dem Server nachmelden, falls er die
            // Zeile nicht (mehr) hat. `updateOrCreate` drueben macht das
            // folgenlos, wenn er sie schon kennt.
            await fetch(adressen.subscribe, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...csrfHeaders(),
                },
                body: JSON.stringify(abo.toJSON()),
            });

            setStatus('abonniert');
        } catch {
            // Eine Registrierung, die sich nicht lesen lässt, heißt für die
            // Oberfläche dasselbe wie keine: Es lässt sich abonnieren.
            setStatus('nicht-abonniert');
        }
    }, [adressen.publicKey, adressen.subscribe]);

    useEffect(() => {
        void pruefen();
    }, [pruefen]);

    const subscribe = useCallback(async (): Promise<void> => {
        if (busy) {
            return;
        }

        setBusy(true);
        setError(null);

        try {
            const erlaubnis = await Notification.requestPermission();

            if (erlaubnis !== 'granted') {
                setStatus(erlaubnis === 'denied' ? 'abgelehnt' : 'nicht-abonniert');

                return;
            }

            const schluessel = await oeffentlicherSchluessel(adressen.publicKey);

            if (!schluessel) {
                // Ohne Schlüssel gibt es kein Abo. Das ist kein Fehler des
                // Menschen und soll auch nicht so aussehen.
                setStatus('kein-schluessel');

                return;
            }

            // Erst jetzt registrieren: Wer nie auf den Knopf drückt, braucht
            // keinen Service Worker.
            const registrierung = await navigator.serviceWorker.register(adressen.worker);
            await navigator.serviceWorker.ready;

            const neuerSchluessel = schluesselBytes(schluessel);

            // Ein altes Abo mit einem ANDEREN Schluessel steht im Weg.
            //
            // Der Browser laesst pro Registrierung genau ein Abo zu. Liegt
            // dort noch eines, das mit einem anderen `applicationServerKey`
            // entstanden ist, wirft `subscribe()` einen `InvalidStateError`
            // — und zwar jedes Mal wieder. Der Mensch drueckt auf den
            // Knopf, bekommt einen Fehler, und nichts aendert sich.
            //
            // Genau diesen Zustand haben alle, die vor dem Umzug des
            // VAPID-Schluessels zum Brain abonniert hatten (#7993). Das war
            // als „einmal neu abonnieren" eingeplant — nur konnte die
            // Oberflaeche es nicht, weil sie das alte Abo nicht wegraeumte.
            const vorhandenes = await registrierung.pushManager.getSubscription();

            if (vorhandenes !== null && !gleicherSchluessel(vorhandenes, neuerSchluessel)) {
                await vorhandenes.unsubscribe();
            }

            const abo = await registrierung.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: neuerSchluessel,
            });

            const gespeichert = await fetch(adressen.subscribe, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...csrfHeaders(),
                },
                body: JSON.stringify(abo.toJSON()),
            });

            if (!gespeichert.ok) {
                // Das Abo im Browser wieder wegnehmen: Sonst steht dort eine
                // Erlaubnis, an die nie jemand etwas schickt — und ein
                // zweiter Versuch fände ein scheinbar fertiges Abo vor.
                await abo.unsubscribe();

                setStatus('nicht-abonniert');
                setError('Das Abo konnte nicht hinterlegt werden. Bitte später noch einmal versuchen.');

                return;
            }

            setStatus('abonniert');
        } catch (fehler) {
            // Den Grund nennen, nicht nur das Scheitern. „Hat nicht
            // geklappt" schickt den Menschen zum Support und den Support
            // in die Entwicklerkonsole.
            setError(grundFuer(fehler));
            console.error('[Push] subscribe fehlgeschlagen:', fehler);
            await pruefen();
        } finally {
            setBusy(false);
        }
    }, [adressen.publicKey, adressen.subscribe, adressen.worker, busy, pruefen]);

    const unsubscribe = useCallback(async (): Promise<void> => {
        if (busy) {
            return;
        }

        setBusy(true);
        setError(null);

        try {
            const registrierung = await navigator.serviceWorker.getRegistration();
            const abo = await registrierung?.pushManager.getSubscription();

            if (abo) {
                const endpoint = abo.endpoint;

                // Erst im Browser weg, dann beim Hub abmelden. Umgekehrt
                // bliebe bei einem Abbruch dazwischen ein Abo stehen, das
                // niemand mehr kennt.
                await abo.unsubscribe();

                await fetch(adressen.subscribe, {
                    method: 'DELETE',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...csrfHeaders(),
                    },
                    body: JSON.stringify({ endpoint }),
                });
            }

            setStatus('nicht-abonniert');
        } catch (fehler) {
            setError('Das Abbestellen hat nicht geklappt.');
            console.error('[Push] unsubscribe fehlgeschlagen:', fehler);
        } finally {
            setBusy(false);
        }
    }, [adressen.subscribe, busy]);

    return { status, busy, error, subscribe, unsubscribe };
}

/**
 * Steckt im vorhandenen Abo derselbe Schluessel wie der, den wir setzen wollen?
 *
 * `options.applicationServerKey` gibt der Browser als ArrayBuffer zurueck —
 * byteweise vergleichen ist der einzige verlaessliche Weg. Kennt ein Browser
 * `options` nicht, gilt das Abo als fremd: lieber einmal zu viel neu
 * abonnieren als dauerhaft an einem alten haengen.
 */
export function gleicherSchluessel(abo: PushSubscription, schluessel: ArrayBuffer): boolean {
    const vorhanden = abo.options?.applicationServerKey;

    if (!vorhanden) {
        return false;
    }

    const a = new Uint8Array(vorhanden);
    const b = new Uint8Array(schluessel);

    return a.length === b.length && a.every((wert, i) => wert === b[i]);
}

/**
 * Ein Satz, der sagt, woran es lag.
 *
 * Die Namen kommen vom Browser und sind stabil; der Text daneben ist fuer
 * jemanden geschrieben, der gerade nur einen Knopf gedrueckt hat.
 */
export function grundFuer(fehler: unknown): string {
    const name = fehler instanceof Error ? fehler.name : '';

    if (name === 'NotAllowedError') {
        return 'Dieser Browser hat Benachrichtigungen für die Seite gesperrt. In den Seiteneinstellungen des Browsers wieder erlauben.';
    }

    if (name === 'InvalidStateError') {
        return 'Auf diesem Gerät liegt noch ein altes Abo. Lade die Seite neu und versuche es noch einmal — beim zweiten Versuch wird es ersetzt.';
    }

    if (name === 'NotSupportedError' || name === 'AbortError') {
        return 'Dieser Browser kann keine Push-Nachrichten empfangen. Auf dem iPhone muss die Seite dafür zum Home-Bildschirm hinzugefügt werden.';
    }

    return 'Das Abonnieren hat nicht geklappt. Die Entwicklerkonsole nennt den Grund.';
}

/**
 * Den oeffentlichen VAPID-Schluessel holen — oder `null`.
 *
 * Von zwei Stellen gebraucht: beim Abonnieren und beim Pruefen, ob ein
 * vorhandenes Abo noch zum aktuellen Schluessel passt.
 */
async function oeffentlicherSchluessel(adresse: string): Promise<string | null> {
    try {
        const antwort = await fetch(adresse, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });

        return antwort.ok ? ((await antwort.json()).public_key ?? null) : null;
    } catch {
        return null;
    }
}
