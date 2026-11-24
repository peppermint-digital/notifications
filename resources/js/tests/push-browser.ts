import { vi } from 'vitest';

/** Einen Browser bauen, der Push kann — jsdom kann es von sich aus nicht. */
export function browserMit({
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

/** Server, der den Schluessel nennt und auf die Status-Frage `status` antwortet. */
export function antwortenMit(status: { bekannt: boolean | null }) {
    return vi.fn(async (url: string) => {
        if (String(url).includes('public-key')) {
            return new Response(JSON.stringify({ public_key: 'QUJDREVGRw' }), { status: 200 });
        }

        if (String(url).endsWith('/status')) {
            return new Response(JSON.stringify(status), { status: 200 });
        }

        return new Response(JSON.stringify({ ok: true }), { status: 200 });
    });
}
