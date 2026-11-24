// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PushToggle } from '../src/PushToggle';
import { antwortenMit, browserMit } from './push-browser';

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

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

            // Genau die Abo-Adresse — `/api/push/subscribe/status` ist
            // seit v0.33 ebenfalls ein POST und zaehlt hier nicht.
            expect(rufe.some(([url, init]) => String(url) === '/api/push/subscribe' && init?.method === 'POST')).toBe(true);
        });
    });

    it('zeigt „kein Push", wenn der Server das Abo nicht mehr kennt — und meldet es nicht nach', async () => {
        // pm #10206: Antwortet der Push-Dienst mit 404/410, loescht der
        // Server die Zeile. Der Browser weiss davon nichts. Bis v0.32 wurde
        // das tote Abo hier still neu angemeldet und der Schalter stand auf
        // gruen — fuer einen Endpunkt, an den nie wieder etwas geht.
        browserMit({ abo: { endpoint: 'https://push.test/abc' } });
        vi.stubGlobal('fetch', antwortenMit({ bekannt: false }));

        render(<PushToggle />);

        await waitFor(() => expect(screen.getByText('Dieses Gerät bekommt kein Push')).toBeDefined());

        const rufe = (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls;
        expect(rufe.some(([url]) => String(url) === '/api/push/subscribe')).toBe(false);
    });

    it('zeigt „bekommt Push", wenn der Server das Abo kennt', async () => {
        browserMit({ abo: { endpoint: 'https://push.test/abc' } });
        vi.stubGlobal('fetch', antwortenMit({ bekannt: true }));

        render(<PushToggle />);

        await waitFor(() => expect(screen.getByText('Dieses Gerät bekommt Push')).toBeDefined());
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
            expect(screen.getByText(/Der Browser blockiert Benachrichtigungen/)).toBeDefined(),
        );
    });

    it('nennt im gesperrten Zustand die Schritte — samt Neuladen', async () => {
        /*
         * Der alte Text sagte richtig, dass die Erlaubnis dem Browser
         * gehoert, und liess den Menschen dann allein. Volker sass darin
         * fest; die naheliegende Reaktion war „aus und wieder an", und die
         * hilft nicht.
         *
         * Das NEULADEN ist der Teil, der sonst fehlt: Die Pruefung liest
         * `Notification.permission` nur beim Einhaengen. Wer die Erlaubnis
         * umstellt und zurueckkommt, sieht denselben Kasten und haelt den
         * Fix fuer wirkungslos.
         */
        browserMit({ permission: 'denied' });

        render(<PushToggle />);

        await waitFor(() => expect(screen.getByText(/Schloss-Symbol/)).toBeDefined());

        expect(screen.getByText(/neu laden/i)).toBeDefined();
        // Und der Hinweis, dass die Sperre auch von selbst entsteht — sonst
        // sucht jemand einen Fehler bei sich, den er nicht gemacht hat.
        expect(screen.getByText(/von selbst/)).toBeDefined();
    });

    it('rendert die Liste nicht in einem Absatz', async () => {
        // Ein Blockelement in einem `p` ist ungueltiges HTML; der Browser
        // bricht die Verschachtelung dann selbst auf und die Abstaende
        // sitzen falsch.
        browserMit({ permission: 'denied' });

        const { container } = render(<PushToggle />);

        await waitFor(() => expect(container.querySelector('ol')).not.toBeNull());

        expect(container.querySelector('p ol')).toBeNull();
    });
});

describe('Abonnieren', () => {
    it('ersetzt ein Abo, das der Server nicht mehr kennt, statt es wiederzubeleben', async () => {
        // Gleicher Schluessel, aber tot: Ohne Abmelden gaebe der Browser
        // bei `subscribe()` genau dieses Abo zurueck — derselbe Endpunkt,
        // den der Push-Dienst schon verworfen hat.
        const vorhandenesAbmelden = vi.fn(async () => true);
        const { subscribe } = browserMit({ abo: { endpoint: 'https://push.test/tot', unsubscribe: vorhandenesAbmelden } });
        vi.stubGlobal('fetch', antwortenMit({ bekannt: false }));

        render(<PushToggle />);
        await waitFor(() => screen.getByText('Einschalten'));

        fireEvent.click(screen.getByText('Einschalten'));

        await waitFor(() => expect(screen.getByText('Dieses Gerät bekommt Push')).toBeDefined());
        expect(vorhandenesAbmelden).toHaveBeenCalled();
        expect(subscribe).toHaveBeenCalled();
    });

    it('laesst ein Abo stehen, ueber das der Server nichts weiss', async () => {
        // `bekannt: null` heisst Hub-Betrieb oder kein Push — dort
        // entscheidet der Server nicht, also wird auch nichts weggeworfen.
        const vorhandenesAbmelden = vi.fn(async () => true);
        browserMit({ abo: { endpoint: 'https://push.test/abc', unsubscribe: vorhandenesAbmelden } });
        vi.stubGlobal('fetch', antwortenMit({ bekannt: null }));

        render(<PushToggle />);

        await waitFor(() => expect(screen.getByText('Dieses Gerät bekommt Push')).toBeDefined());
        expect(vorhandenesAbmelden).not.toHaveBeenCalled();
    });

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
