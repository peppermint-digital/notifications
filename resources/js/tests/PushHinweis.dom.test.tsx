// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PushHinweis } from '../src/PushHinweis';
import { antwortenMit, browserMit } from './push-browser';

/**
 * Der Hinweis „Push auf diesem Geraet aus" (pm #10206).
 *
 * Er steht, solange auf DIESEM Geraet nichts ankommt — damit jeder sofort
 * sieht, warum er keine Push-Nachrichten bekommt. Wo der Browser es gar
 * nicht kann, waere er nur Laerm.
 */
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    window.sessionStorage.clear();
    window.localStorage.clear();
});

beforeEach(() => {
    vi.stubGlobal('fetch', antwortenMit({ bekannt: null }));
});

const HINWEIS = /auf diesem Gerät aus/;

describe('Wann er erscheint', () => {
    it('erscheint ohne Abo, mit Knopf zum Einschalten', async () => {
        browserMit({ abo: null });

        render(<PushHinweis />);

        await waitFor(() => expect(screen.getByText(HINWEIS)).toBeDefined());
        expect(screen.getByRole('button', { name: 'Einschalten' })).toBeDefined();
    });

    it('erscheint, wenn der Server das Abo nicht mehr kennt', async () => {
        browserMit({ abo: { endpoint: 'https://push.test/tot' } });
        vi.stubGlobal('fetch', antwortenMit({ bekannt: false }));

        render(<PushHinweis />);

        await waitFor(() => expect(screen.getByText(HINWEIS)).toBeDefined());
    });

    it('erscheint nicht, wenn Push auf diesem Geraet laeuft', async () => {
        browserMit({ abo: { endpoint: 'https://push.test/abc' } });
        vi.stubGlobal('fetch', antwortenMit({ bekannt: true }));

        const { container } = render(<PushHinweis />);

        // Erst warten, bis der Zustand feststeht — sonst waere „leer" nur
        // das Pruefen.
        await waitFor(() => {
            const rufe = (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls;
            expect(rufe.some(([url]) => String(url).endsWith('/status'))).toBe(true);
        });
        await new Promise((fertig) => setTimeout(fertig, 0));

        expect(container.innerHTML).toBe('');
    });

    it('erscheint nicht, wenn der Browser kein Push kann', async () => {
        browserMit({ kannPush: false });

        const { container } = render(<PushHinweis />);
        await new Promise((fertig) => setTimeout(fertig, 0));

        expect(container.innerHTML).toBe('');
    });

    it('erscheint nicht, wenn die Anmeldung abgelaufen ist', async () => {
        // Ueber das Abo ist dann nichts zu sagen; die Seite selbst
        // schickt ohnehin zur Anmeldung.
        browserMit({ abo: {} });
        vi.stubGlobal('fetch', vi.fn(async () => new Response('', { status: 401 })));

        const { container } = render(<PushHinweis />);
        await waitFor(() => expect(globalThis.fetch).toHaveBeenCalled());
        await new Promise((fertig) => setTimeout(fertig, 0));

        expect(container.innerHTML).toBe('');
    });
});

describe('Gesperrte Erlaubnis', () => {
    it('nennt die Schritte statt eines Knopfs, der nichts kann', async () => {
        // Bei `denied` fragt der Browser nicht mehr — ein Einschalten-Knopf
        // waere ein Knopf ohne Wirkung.
        browserMit({ permission: 'denied' });

        render(<PushHinweis />);

        await waitFor(() => expect(screen.getByText(/blockiert/)).toBeDefined());
        expect(screen.queryByRole('button', { name: 'Einschalten' })).toBeNull();
        expect(screen.getByText(/Zulassen/)).toBeDefined();
        expect(screen.getByText(/neu laden/)).toBeDefined();
    });
});

describe('Gesperrt in der App vom Home-Bildschirm', () => {
    // Dort gibt es keine Adresszeile und kein Schloss — die Erlaubnis
    // steht in den Einstellungen des Handys (Bastian, 24.11.2026).
    const alsApp = (userAgent: string) => {
        vi.stubGlobal('matchMedia', (abfrage: string) => ({
            matches: abfrage === '(display-mode: standalone)',
            media: abfrage,
            addEventListener: () => {},
            removeEventListener: () => {},
        }));
        vi.spyOn(navigator, 'userAgent', 'get').mockReturnValue(userAgent);
    };

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('nennt am iPhone die Mitteilungen in den Einstellungen statt der Adresszeile', async () => {
        alsApp('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15');
        browserMit({ permission: 'denied' });

        render(<PushHinweis />);

        await waitFor(() => expect(screen.getByText(/blockiert/)).toBeDefined());
        expect(screen.getByText(/Einstellungen → Mitteilungen/)).toBeDefined();
        expect(screen.queryByText(/Adresszeile/)).toBeNull();
    });

    it('nennt an Android die App-Info', async () => {
        alsApp('Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/131.0 Mobile Safari/537.36');
        browserMit({ permission: 'denied' });

        render(<PushHinweis />);

        await waitFor(() => expect(screen.getByText(/blockiert/)).toBeDefined());
        expect(screen.getByText(/App-Info/)).toBeDefined();
        expect(screen.queryByText(/Adresszeile/)).toBeNull();
    });

    it('bleibt im Browser bei der Adresszeile', async () => {
        browserMit({ permission: 'denied' });

        render(<PushHinweis />);

        await waitFor(() => expect(screen.getByText(/Adresszeile/)).toBeDefined());
    });
});

describe('Einschalten', () => {
    it('schaltet mit einem Klick ein und verschwindet dann', async () => {
        const { subscribe } = browserMit({ abo: null });

        render(<PushHinweis />);
        await waitFor(() => screen.getByRole('button', { name: 'Einschalten' }));

        fireEvent.click(screen.getByRole('button', { name: 'Einschalten' }));

        await waitFor(() => expect(screen.queryByText(HINWEIS)).toBeNull());
        expect(subscribe).toHaveBeenCalled();
    });

    it('nennt den Grund, wenn es scheitert, und bleibt stehen', async () => {
        browserMit({ abo: null });
        vi.stubGlobal(
            'fetch',
            vi.fn(async (url: string) =>
                String(url).includes('public-key')
                    ? new Response(JSON.stringify({ public_key: 'QUJDREVGRw' }), { status: 200 })
                    : new Response(JSON.stringify({ ok: false }), { status: 503 }),
            ),
        );

        render(<PushHinweis />);
        await waitFor(() => screen.getByRole('button', { name: 'Einschalten' }));

        fireEvent.click(screen.getByRole('button', { name: 'Einschalten' }));

        await waitFor(() => expect(screen.getByText(/konnte nicht hinterlegt werden/)).toBeDefined());
        expect(screen.getByText(HINWEIS)).toBeDefined();
    });
});

describe('Später', () => {
    it('blendet nur fuer die laufende Sitzung aus', async () => {
        browserMit({ abo: null });

        const erst = render(<PushHinweis />);
        await waitFor(() => screen.getByRole('button', { name: 'Später' }));

        fireEvent.click(screen.getByRole('button', { name: 'Später' }));

        expect(screen.queryByText(HINWEIS)).toBeNull();
        erst.unmount();

        // Naechste Seite in derselben Sitzung: bleibt weg.
        const zweit = render(<PushHinweis />);
        await new Promise((fertig) => setTimeout(fertig, 0));
        expect(screen.queryByText(HINWEIS)).toBeNull();
        zweit.unmount();

        // Nichts Dauerhaftes: Bis v1.270 merkte sich der Manager das
        // Wegklicken fuer immer — danach erfuhr niemand mehr, warum nichts
        // ankommt.
        expect(window.localStorage.length).toBe(0);

        // Neue Sitzung: wieder da.
        window.sessionStorage.clear();
        render(<PushHinweis />);

        await waitFor(() => expect(screen.getByText(HINWEIS)).toBeDefined());
    });

    it('gibt es auch bei der gesperrten Erlaubnis', async () => {
        browserMit({ permission: 'denied' });

        render(<PushHinweis />);
        await waitFor(() => screen.getByRole('button', { name: 'Später' }));

        fireEvent.click(screen.getByRole('button', { name: 'Später' }));

        expect(screen.queryByText(/blockiert/)).toBeNull();
    });
});
