import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { NotificationFeed, NotificationItem } from './types';

/**
 * Die Gegenseite zu NotificationFeed und NotificationActions auf dem Server.
 *
 * Alle Aktionen arbeiten auf `ids`, nicht auf einer einzelnen Kennung: Die
 * Liste bündelt Gleichartiges zu einer Zeile, hinter der mehrere Datensätze
 * stecken. Eine Aktion, die nur einen trifft, lässt die Zeile stehen und den
 * Zähler falsch.
 */

export interface NotificationRoutes {
    /** Vorgabe: `/notifications` */
    index?: string;
    unreadCount?: string;
    read?: string;
    readAll?: string;
    unread?: string;
    destroy?: string;
    destroyAll?: string;
}

const VORGABE: Required<NotificationRoutes> = {
    index: '/notifications',
    unreadCount: '/notifications/unread-count',
    read: '/notifications/read',
    readAll: '/notifications/read-all',
    unread: '/notifications/unread',
    destroy: '/notifications',
    destroyAll: '/notifications/all',
};

export interface UseNotificationsOptions {
    routes?: NotificationRoutes;
    /** Abfragetakt in Millisekunden. 0 schaltet das Nachladen ab. */
    pollMs?: number;
    /** Vorbefüllung, etwa aus Server-Props — spart das erste Laden. */
    initial?: NotificationFeed;
}

/**
 * Das CSRF-Token aus dem Dokumentenkopf — wenn es eines gibt.
 *
 * Inertia-Anwendungen haben oft KEIN `<meta name="csrf-token">`; ihr Client
 * schickt den XSRF-TOKEN-Cookie als Kopfzeile. Wer blind `querySelector`
 * liest, bekommt `null` und schickt einen leeren Kopf. Deshalb wird hier
 * beides versucht und der Kopf weggelassen, wenn es nichts zu schicken gibt —
 * ein leerer Kopf täuscht einen Mechanismus vor, den es nicht gibt.
 */
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

/**
 * Eine abgelaufene Sitzung meldet sich als 419. Ohne Neuladen liefe die
 * Oberfläche danach still ins Leere — jeder Klick quittungslos.
 */
function abgelaufen(response: Response): boolean {
    if (response.status !== 419) {
        return false;
    }

    console.warn('[Notifications] Sitzung abgelaufen, lade neu.');
    window.location.reload();

    return true;
}

export interface UseNotificationsResult extends NotificationFeed {
    loading: boolean;
    /** Neu laden. */
    refresh: () => Promise<void>;
    markRead: (ids: Array<number | string>) => Promise<void>;
    markAllRead: () => Promise<void>;
    markUnread: (ids: Array<number | string>) => Promise<void>;
    remove: (ids: Array<number | string>) => Promise<void>;
    removeAll: () => Promise<void>;
    /** Die Zeile erledigen, in der Form, die zu ihrer Quelle passt. */
    resolve: (item: NotificationItem) => Promise<void>;
}

export function useNotifications(options: UseNotificationsOptions = {}): UseNotificationsResult {
    const { pollMs = 60_000, initial } = options;
    const routes = useMemo(() => ({ ...VORGABE, ...options.routes }), [options.routes]);

    const [feed, setFeed] = useState<NotificationFeed>(initial ?? { items: [], unreadCount: 0 });
    const [loading, setLoading] = useState(!initial);

    // Ein Verweis, damit das Nachladen nicht bei jedem Renderdurchlauf einen
    // neuen Zeitgeber aufsetzt.
    const laufend = useRef(false);

    const refresh = useCallback(async (): Promise<void> => {
        if (laufend.current) {
            return;
        }

        laufend.current = true;

        try {
            const response = await fetch(routes.index, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (abgelaufen(response) || !response.ok) {
                return;
            }

            const daten = (await response.json()) as {
                items: unknown[];
                unread_count: number;
            };

            setFeed({
                items: daten.items.map(normalisieren),
                unreadCount: daten.unread_count ?? 0,
            });
        } catch (fehler) {
            // Ein Netzaussetzer ist kein Grund, die Liste zu leeren: Der
            // letzte gute Stand bleibt stehen, der nächste Takt versucht es
            // erneut. Eine leere Glocke wäre die falsche Aussage.
            console.error('[Notifications] Laden fehlgeschlagen:', fehler);
        } finally {
            laufend.current = false;
            setLoading(false);
        }
    }, [routes.index]);

    const senden = useCallback(
        async (url: string, method: 'POST' | 'DELETE', body?: Record<string, unknown>): Promise<void> => {
            try {
                const response = await fetch(url, {
                    method,
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...csrfHeaders(),
                    },
                    body: body ? JSON.stringify(body) : undefined,
                });

                if (abgelaufen(response)) {
                    return;
                }
            } catch (fehler) {
                console.error(`[Notifications] ${method} ${url} fehlgeschlagen:`, fehler);
            }

            // Nach jeder Aktion neu laden statt die Liste örtlich zu ändern:
            // Der Server bleibt die Wahrheit, auch für die Zahl am Abzeichen.
            await refresh();
        },
        [refresh],
    );

    useEffect(() => {
        if (!initial) {
            void refresh();
        }

        if (pollMs <= 0) {
            return;
        }

        const takt = setInterval(() => void refresh(), pollMs);

        return () => clearInterval(takt);
        // `initial` absichtlich nicht in der Liste: Es ist die Vorbefüllung
        // des ersten Durchlaufs, kein Auslöser für erneutes Laden.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [refresh, pollMs]);

    const markRead = useCallback((ids: Array<number | string>) => senden(routes.read, 'POST', { ids }), [senden, routes.read]);

    return {
        ...feed,
        loading,
        refresh,
        markRead,
        markAllRead: useCallback(() => senden(routes.readAll, 'POST'), [senden, routes.readAll]),
        markUnread: useCallback((ids) => senden(routes.unread, 'POST', { ids }), [senden, routes.unread]),
        remove: useCallback((ids) => senden(routes.destroy, 'DELETE', { ids }), [senden, routes.destroy]),
        removeAll: useCallback(() => senden(routes.destroyAll, 'DELETE'), [senden, routes.destroyAll]),
        resolve: useCallback((item: NotificationItem) => markRead(item.ids), [markRead]),
    };
}

/**
 * Die Antwort des Servers in die Form des Pakets bringen.
 *
 * Der Server spricht Schlangenschrift (`bundle_count`), das Frontend
 * Höckerschrift. Die Umschreibung steht hier und nicht in jeder Komponente —
 * sonst entscheidet jede Ansicht neu, wie das Feld heißt.
 */
/**
 * Eine Serverantwort in die Form des Pakets bringen.
 *
 * Der Haken tut das fuer seine eigenen Abrufe von selbst. Nach aussen gereicht
 * wird es fuer die VORBEFUELLUNG aus Server-Props, die am Haken vorbeikommt:
 * Jedes Produkt hatte diese Umschreibung sonst selbst zu schreiben, und beim
 * ersten Mal fiel dabei prompt `source` unter den Tisch — womit der Filter
 * nach Herkunft auf der vorbefuellten Seite leer blieb.
 */
export function normalizeFeed(roh: { items?: unknown[]; unread_count?: number } | null | undefined): NotificationFeed {
    return {
        items: (roh?.items ?? []).map(normalisieren),
        unreadCount: roh?.unread_count ?? 0,
    };
}

function normalisieren(roh: unknown): NotificationItem {
    const r = roh as Record<string, unknown>;

    return {
        id: r.id as number | string,
        ids: (r.ids as Array<number | string>) ?? [r.id as number | string],
        bundleCount: (r.bundle_count as number) ?? 1,
        type: (r.type as string) ?? '',
        typeLabel: (r.type_label as string) ?? '',
        title: (r.title as string) ?? '',
        message: (r.message as string | null) ?? null,
        icon: (r.icon as string) ?? 'bell',
        color: (r.color as string) ?? 'gray',
        link: (r.link as string | null) ?? null,
        triggeredBy: (r.triggered_by as string | null) ?? null,
        triggeredById: (r.triggered_by_id as number | string | null) ?? null,
        subjectType: (r.subject_type as string | null) ?? null,
        subjectId: (r.subject_id as number | string | null) ?? null,
        isRead: Boolean(r.is_read),
        createdAt: (r.created_at as string) ?? '',
        source: (r.source as string | null) ?? null,
    };
}
