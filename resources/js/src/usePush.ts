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
    /**
     * Die Anmeldung ist weg — der Server hat mit 401 geantwortet.
     *
     * Eigener Zustand und nicht `kein-schluessel`. Genau diese Verwechslung
     * war Bug #1068: Jede nicht-ok-Antwort wurde zu „Es kommt kein Schlüssel
     * von AI Brain". Zwei Menschen meldeten daraufhin, Push sei kaputt —
     * während in Wahrheit nur ihre Sitzung abgelaufen war. Die Maske
     * beschuldigte den Hub, und an ihrer Auskunft war nicht ablesbar, was zu
     * tun ist.
     *
     * Nachgemessen am 22.10.2026: Alle vier Schlüssel-Endpunkte antworten
     * ohne Sitzung mit 401 — in AI Brain, Manager, CRM und Verwaltung.
     */
    | 'nicht-angemeldet'
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
            const ergebnis = await oeffentlicherSchluessel(adressen.publicKey);

            if (ergebnis.art === 'nicht-angemeldet') {
                // Ohne Anmeldung ist ueber das Abo gar nichts zu sagen —
                // auch nicht, dass es „nicht abonniert" waere. Siehe #1068.
                setStatus('nicht-angemeldet');

                return;
            }

            if (ergebnis.art === 'keiner' || !gleicherSchluessel(abo, schluesselBytes(ergebnis.schluessel))) {
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

            const ergebnis = await oeffentlicherSchluessel(adressen.publicKey);

            if (ergebnis.art === 'nicht-angemeldet') {
                // DIE Stelle aus Bug #1068. Vorher landete das hier unter
                // „kein Schlüssel" — und die Maske erklärte einem Menschen,
                // dessen Sitzung abgelaufen war, das Brain liefere nichts.
                setStatus('nicht-angemeldet');

                return;
            }

            if (ergebnis.art === 'keiner') {
                // Ohne Schlüssel gibt es kein Abo. Das ist kein Fehler des
                // Menschen und soll auch nicht so aussehen.
                setStatus('kein-schluessel');

                return;
            }

            // Erst jetzt registrieren: Wer nie auf den Knopf drückt, braucht
            // keinen Service Worker.
            const registrierung = await navigator.serviceWorker.register(adressen.worker);
            await navigator.serviceWorker.ready;

            const neuerSchluessel = schluesselBytes(ergebnis.schluessel);

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

                // Auch hier die Anmeldung getrennt benennen (#1068): „Bitte
                // später noch einmal versuchen" ist bei einer abgelaufenen
                // Sitzung ein Rat, der nie funktioniert — später ist sie
                // genauso abgelaufen.
                if (gespeichert.status === 401 || gespeichert.status === 419) {
                    setStatus('nicht-angemeldet');

                    return;
                }

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
 * Was beim Holen des Schluessels herauskam.
 *
 * DREI Faelle, nicht zwei — das ist der Kern von Bug #1068. Vorher gab diese
 * Funktion nur `string | null` zurueck, und damit war „der Server kennt
 * keinen Schluessel" nicht von „du bist nicht mehr angemeldet" zu
 * unterscheiden. Die Oberflaeche zeigte fuer beides denselben Satz, und der
 * nannte die falsche Ursache.
 */
export type SchluesselErgebnis =
    | { art: 'da'; schluessel: string }
    | { art: 'nicht-angemeldet' }
    | { art: 'keiner' };

/**
 * Den oeffentlichen VAPID-Schluessel holen.
 *
 * Von zwei Stellen gebraucht: beim Abonnieren und beim Pruefen, ob ein
 * vorhandenes Abo noch zum aktuellen Schluessel passt.
 *
 * 401 und 419 bekommen einen eigenen Fall: Das erste ist die abgelaufene
 * Sitzung, das zweite der abgelaufene CSRF-Token — fuer den Menschen
 * dasselbe („melde dich neu an"), und beides hat nichts mit dem Schluessel
 * zu tun.
 */
export async function oeffentlicherSchluessel(adresse: string): Promise<SchluesselErgebnis> {
    try {
        const antwort = await fetch(adresse, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (antwort.status === 401 || antwort.status === 419) {
            return { art: 'nicht-angemeldet' };
        }

        if (!antwort.ok) {
            return { art: 'keiner' };
        }

        const schluessel = (await antwort.json()).public_key ?? null;

        return typeof schluessel === 'string' && schluessel !== ''
            ? { art: 'da', schluessel }
            : { art: 'keiner' };
    } catch {
        // Netz weg, DNS kaputt, Seite offline: Hier ist „keiner" richtig —
        // wir wissen nichts ueber die Anmeldung.
        return { art: 'keiner' };
    }
}
