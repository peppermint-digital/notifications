// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PushToggle } from '../src/PushToggle';

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

/** Einen Browser bauen, der Push kann — jsdom kann es von sich aus nicht. */
function browserMit({
    permission = 'granted',
    abo = null,
    kannPush = true,
}: { permission?: string; abo?: unknown; kannPush?: boolean } = {}) {
    const subscribe = vi.fn(async () => ({
        endpoint: 'https://push.test/abc',
        toJSON: () => ({ endpoint: 'https://push.test/abc', keys: { p256dh: 'P', auth: 'A' } }),
        unsubscribe: vi.fn(async () => true),
        // Seit v0.22 prueft der Haken, ob das Abo zum AKTUELLEN Schluessel
        // passt — ein Abo mit fremdem Schluessel ist tot und wird als
        // „nicht abonniert" gezeigt, damit der Knopf es ersetzen kann.
        options: { applicationServerKey: new TextEncoder().encode('ABCDEFG').buffer },
    }));

    // Ein vorhandenes Abo traegt per Vorgabe den AKTUELLEN Schluessel und
    // kann sich serialisieren — beides braucht der Haken seit v0.22, um
    // ein totes Abo von einem gueltigen zu unterscheiden und ein
    // gueltiges beim Server nachzumelden. Ein Test, der ein ALTES Abo
    // nachstellen will, gibt eigene `options` mit.
    const vorhandenes = abo === null
        ? null
        : {
            toJSON: () => ({ endpoint: 'https://push.test/abc', keys: { p256dh: 'P', auth: 'A' } }),
            options: { applicationServerKey: new TextEncoder().encode('ABCDEFG').buffer },
            unsubscribe: vi.fn(async () => true),
            ...(abo as Record<string, unknown>),
        };

    const registrierung = {
        pushManager: { getSubscription: vi.fn(async () => vorhandenes), subscribe },
    };

    if (kannPush) {
        vi.stubGlobal('PushManager', function () {});
        vi.stubGlobal('Notification', {
            permission,
            requestPermission: vi.fn(async () => permission),
        });
        Object.defineProperty(navigator, 'serviceWorker', {
            configurable: true,
            value: {
                getRegistration: vi.fn(async () => registrierung),
                register: vi.fn(async () => registrierung),
                ready: Promise.resolve(registrierung),
            },
        });
    } else {
        vi.stubGlobal('PushManager', undefined);
        // @ts-expect-error — absichtlich entfernt
        delete (navigator as Record<string, unknown>).serviceWorker;
    }

    return { subscribe };
}

beforeEach(() => {
    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string) => {
            if (String(url).includes('public-key')) {
                return new Response(JSON.stringify({ public_key: 'QUJDREVGRw' }), { status: 200 });
            }

            return new Response(JSON.stringify({ ok: true }), { status: 200 });
        }),
    );
});

describe('Zustand aus dem Browser', () => {
    it('zeigt „kein Push", wenn noch kein Abo besteht', async () => {
        browserMit({ abo: null });

        render(<PushToggle />);

        await waitFor(() => expect(screen.getByText('Dieses Gerät bekommt kein Push')).toBeDefined());
    });

    it('zeigt „bekommt Push", wenn der Browser ein Abo mit dem aktuellen Schluessel hat', async () => {
        // Der Zustand kommt aus dem Browser, nicht aus einem Server-Flag:
        // Ein Flag stuende weiter auf „abonniert", nachdem jemand die
        // Website-Daten geloescht hat.
        browserMit({ abo: { endpoint: 'https://push.test/abc' } });

        render(<PushToggle />);

        await waitFor(() => expect(screen.getByText('Dieses Gerät bekommt Push')).toBeDefined());
    });

    it('zeigt „kein Push", wenn das Abo einen ALTEN Schluessel traegt', async () => {
        // Der Fall, der einen Kollegen blockierte: Der Browser hielt ein
        // Abo vom alten VAPID-Schluessel, der Server kannte es nicht, und
        // der Schalter stand trotzdem auf gruen. Ein gruener Schalter
        // ohne Wirkung ist schlimmer als ein grauer — man merkt nicht,
        // dass man nichts bekommt.
        browserMit({
            abo: {
                endpoint: 'https://push.test/alt',
                options: { applicationServerKey: new TextEncoder().encode('ALTERKEY').buffer },
            },
        });

        render(<PushToggle />);

        await waitFor(() => expect(screen.getByText('Dieses Gerät bekommt kein Push')).toBeDefined());
    });

    it('meldet ein gueltiges Abo beim Server nach', async () => {
        // Loescht jemand drueben die Zeile (oder ging sie beim Umzug
        // verloren), weiss der Server nichts von einem Abo, das im
        // Browser laengst steht. Dann kommt nie etwas an, und niemand
        // sieht warum. Der Haken meldet es stillschweigend nach;
        // `updateOrCreate` drueben macht das folgenlos.
        browserMit({ abo: { endpoint: 'https://push.test/abc' } });

        render(<PushToggle />);

        await waitFor(() => {
            const rufe = (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls;

            expect(rufe.some(([url, init]) => String(url).includes('subscribe') && init?.method === 'POST')).toBe(true);
        });
    });

    it('sagt es, wenn der Browser gar kein Push kann', async () => {
        browserMit({ kannPush: false });

        render(<PushToggle />);

        await waitFor(() =>
            expect(screen.getByText('Dieser Browser kann keine Push-Benachrichtigungen')).toBeDefined(),
        );
    });

    it('verweist auf die Browser-Einstellungen, wenn blockiert wurde', async () => {
        // Das laesst sich in der Anwendung nicht aufheben — ein Knopf, der
        // es versuchte, waere eine Luege.
        browserMit({ permission: 'denied' });

        render(<PushToggle />);

        await waitFor(() =>
            expect(screen.getByText('Benachrichtigungen sind für diese Seite blockiert')).toBeDefined(),
        );
    });
});

describe('Abonnieren', () => {
    it('schickt das Abo an den Server', async () => {
        browserMit({ abo: null });

        render(<PushToggle />);
        await waitFor(() => screen.getByText('Einschalten'));

        fireEvent.click(screen.getByText('Einschalten'));

        await waitFor(() => expect(screen.getByText('Dieses Gerät bekommt Push')).toBeDefined());

        const gesendet = (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls
            .map(([url]) => String(url));

        expect(gesendet).toContain('/api/push/subscribe');
    });

    it('sagt es und bleibt unabonniert, wenn es keinen Schluessel gibt', async () => {
        // Ohne Schluessel gibt es kein Abo. Das ist kein Fehler des
        // Menschen und soll auch nicht so aussehen.
        browserMit({ abo: null });
        vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ public_key: null }), { status: 200 })));

        render(<PushToggle />);
        await waitFor(() => screen.getByText('Einschalten'));

        fireEvent.click(screen.getByText('Einschalten'));

        await waitFor(() => expect(screen.getByText('Push ist hier gerade nicht eingerichtet')).toBeDefined());
    });

    /*
     * Bug #1068: Zwei Menschen meldeten, Push sei kaputt — die Maske sagte
     * „Es kommt kein Schluessel von AI Brain". In Wahrheit war ihre Sitzung
     * abgelaufen. Nachgemessen: Alle vier Schluessel-Endpunkte antworten
     * ohne Sitzung mit 401, und jede nicht-ok-Antwort wurde zu „kein
     * Schluessel".
     */
    it('nennt die abgelaufene Anmeldung, statt den Schluessel zu beschuldigen', async () => {
        /*
         * Mit VORHANDENEM Abo — genau Chris' Lage: Er war abonniert, also
         * holt die Pruefung beim Laden den Schluessel, um das Abo gegen ihn
         * zu halten. Ohne Abo wird er beim Laden gar nicht geholt.
         *
         * Vor dem Fix endete das hier in „nicht abonniert": Der Schalter
         * behauptete, dieses Geraet bekomme kein Push — obwohl es eines
         * hatte. Und ein Klick machte daraus „kein Schluessel von AI Brain".
         */
        browserMit({ abo: {} });
        vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ message: 'Unauthenticated.' }), { status: 401 })));

        render(<PushToggle />);
        await waitFor(() => expect(screen.getByText('Deine Anmeldung ist abgelaufen')).toBeDefined());

        // Und ausdruecklich NICHT der alte Satz: Er nannte eine Ursache, an
        // der niemand etwas aendern kann.
        expect(screen.queryByText('Push ist hier gerade nicht eingerichtet')).toBeNull();
    });

    it('nennt die abgelaufene Anmeldung auch beim Klick auf Einschalten', async () => {
        browserMit({ abo: null });
        let ersterAufruf = true;
        vi.stubGlobal('fetch', vi.fn(async () => {
            // Beim Pruefen noch angemeldet, beim Klick nicht mehr — genau
            // der Fall, in dem jemand die Seite eine Weile offen hatte.
            if (ersterAufruf) {
                ersterAufruf = false;

                return new Response(JSON.stringify({ public_key: 'QUJDREVGRw' }), { status: 200 });
            }

            return new Response(JSON.stringify({ message: 'Unauthenticated.' }), { status: 401 });
        }));

        render(<PushToggle />);
        await waitFor(() => screen.getByText('Einschalten'));

        fireEvent.click(screen.getByText('Einschalten'));

        await waitFor(() => expect(screen.getByText('Deine Anmeldung ist abgelaufen')).toBeDefined());
    });

    it('nennt die abgelaufene Anmeldung, wenn erst das Hinterlegen daran scheitert', async () => {
        // „Bitte spaeter noch einmal versuchen" ist bei einer abgelaufenen
        // Sitzung ein Rat, der nie funktioniert — spaeter ist sie genauso
        // abgelaufen.
        browserMit({ abo: null });

        vi.stubGlobal(
            'fetch',
            vi.fn(async (url: string) =>
                String(url).includes('public-key')
                    ? new Response(JSON.stringify({ public_key: 'QUJDREVGRw' }), { status: 200 })
                    : new Response(JSON.stringify({ message: 'Unauthenticated.' }), { status: 401 }),
            ),
        );

        render(<PushToggle />);
        await waitFor(() => screen.getByText('Einschalten'));

        fireEvent.click(screen.getByText('Einschalten'));

        await waitFor(() => expect(screen.getByText('Deine Anmeldung ist abgelaufen')).toBeDefined());
        expect(screen.queryByText(/konnte nicht hinterlegt werden/)).toBeNull();
    });

    it('nimmt das Abo zurueck, wenn der Server es nicht annimmt', async () => {
        // Sonst stuende im Browser eine Erlaubnis, an die nie jemand etwas
        // schickt — und ein zweiter Versuch faende ein scheinbar fertiges
        // Abo vor.
        const { subscribe } = browserMit({ abo: null });

        vi.stubGlobal(
            'fetch',
            vi.fn(async (url: string) =>
                String(url).includes('public-key')
                    ? new Response(JSON.stringify({ public_key: 'QUJDREVGRw' }), { status: 200 })
                    : new Response(JSON.stringify({ ok: false }), { status: 503 }),
            ),
        );

        render(<PushToggle />);
        await waitFor(() => screen.getByText('Einschalten'));

        fireEvent.click(screen.getByText('Einschalten'));

        await waitFor(() => expect(screen.getByText(/konnte nicht hinterlegt werden/)).toBeDefined());

        const angelegt = await subscribe.mock.results[0]!.value;
        expect(angelegt.unsubscribe).toHaveBeenCalled();
    });
});
