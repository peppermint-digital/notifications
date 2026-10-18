import { Inbox, RefreshCw } from 'lucide-react';
import type { ReactNode } from 'react';
import { useMemo, useState } from 'react';
import { NotificationList } from './NotificationList';
import type { NotificationFeed, NotificationItem } from './types';
import { Badge } from './ui/Badge';
import { Button } from './ui/Button';
import { cn } from './ui/utils';
import { useNotifications, type NotificationRoutes } from './useNotifications';

/**
 * Der Posteingang — eine Seite, in jedem Produkt dieselbe.
 *
 * ## Warum es diese Komponente zusätzlich zur Liste gibt
 *
 * {@link NotificationList} ist der Inhalt: Zeilen, Bündelung, Aktionen. Sie
 * steckt im Manager in einem Reiter zwischen Aufgaben-Reitern und kann deshalb
 * keinen eigenen Seitenkopf mitbringen.
 *
 * Diese Komponente ist die SEITE: Kopfzeile, Zähler, Filter, Nachladen — alles
 * um die Liste herum. Ein Produkt bindet sie in sein eigenes Layout ein und
 * schreibt sonst nichts.
 *
 * ## Warum die Herkunft hier zum Filter wird und nicht bloß zur Zeile
 *
 * Seit AI Brain spiegelt, steht in jedem System alles. Das ist gewollt — aber
 * es heißt auch, dass ein Mensch im CRM Meldungen aus dem Manager sieht. Ohne
 * Filter wäre „zeig mir, was hier passiert ist" nicht mehr beantwortbar.
 *
 * Der Filter ist DARSTELLUNG, kein Abruf: Es wird nichts nachgeladen, nur
 * ausgeblendet. Deshalb bleiben die Zahlen an den Reitern immer vollständig
 * und springen nicht, während man filtert.
 */
export interface NotificationPageProps {
    /** Wohin die Aktionen gehen. Vorgabe: die Routen des Pakets. */
    routes?: NotificationRoutes;

    /** Vorbefüllung aus Server-Props — spart das erste Laden. */
    initial?: NotificationFeed;

    /** Abfragetakt in Millisekunden. 0 schaltet das Nachladen ab. */
    pollMs?: number;

    title?: string;

    /**
     * Wie die Herkunft eines Datensatzes heißen soll.
     *
     * Über die Leitung kommt ein Produkt-Kürzel (`peppermint-manager`). Wie es
     * für einen Leser heißt, weiß das Produkt — nicht das Paket.
     */
    sourceLabels?: Record<string, string>;

    /** Name dieses Systems, für alles ohne Herkunft. Vorgabe: „Hier". */
    localLabel?: string;

    formatTime?: (iso: string) => string;

    /** Durchgereicht an die Liste. */
    onOpen?: (item: NotificationItem) => void;
    isDuplicate?: (item: NotificationItem) => boolean;
    renderActions?: (item: NotificationItem) => ReactNode;
    renderDetails?: (item: NotificationItem) => ReactNode;

    /**
     * Systeme, die gerade nicht geantwortet haben.
     *
     * Die Seite sagt es ausdrücklich. Eine still verkürzte Liste sieht aus
     * wie „da ist nichts" — und das ist die gefährlichere Aussage, weil
     * niemand nachfragt.
     */
    unreachable?: string[];

    /** Zusätzliches zwischen Kopf und Filterzeile, etwa ein Hinweisbanner. */
    children?: ReactNode;

    className?: string;
}

/** Die Zeile gehört zu diesem System, wenn sie keine fremde Herkunft trägt. */
const HIER = '__hier__';

export function NotificationPage({
    routes,
    initial,
    pollMs = 60_000,
    title = 'Posteingang',
    sourceLabels = {},
    localLabel = 'Hier',
    formatTime = (iso) => iso,
    onOpen,
    isDuplicate,
    renderActions,
    renderDetails,
    unreachable = [],
    children,
    className,
}: NotificationPageProps) {
    const { items, unreadCount, loading, markRead, markAllRead, remove, removeAll, refresh } = useNotifications({
        routes,
        initial,
        pollMs,
    });

    const [nurUngelesen, setNurUngelesen] = useState(false);
    const [herkunft, setHerkunft] = useState<string | null>(null);

    const schluessel = (item: NotificationItem): string => item.source ?? HIER;

    /**
     * Wie ein System heissen soll.
     *
     * Reihenfolge: was der Holer mitgeliefert hat, dann die Zuordnung des
     * Produkts, dann der rohe Schluessel. Die mitgelieferte Beschriftung
     * steht vorn, weil der Komm-Layer seine eigenen Schluessel vergibt —
     * sie sind nicht zwangslaeufig der Produkt-Slug, und dann griff bisher
     * der Rueckfall: An den Reitern stand klein „crm" neben „Manager".
     */
    const gelieferteNamen = useMemo(() => {
        const namen: Record<string, string> = {};

        for (const item of items) {
            if (item.source && item.sourceLabel) {
                namen[item.source] = item.sourceLabel;
            }
        }

        return namen;
    }, [items]);

    const bezeichnung = (key: string): string =>
        key === HIER ? localLabel : (gelieferteNamen[key] ?? sourceLabels[key] ?? key);

    /**
     * Die Herkünfte, die wirklich vorkommen — mit ihrer Zahl.
     *
     * Aus den Zeilen abgeleitet, nicht aus einer Liste bekannter Produkte: Ein
     * Reiter „CRM (0)", nur weil es ein CRM gibt, sagt nichts und kostet Platz.
     */
    const herkuenfte = useMemo(() => {
        const zaehler = new Map<string, number>();

        for (const item of items) {
            const key = schluessel(item);
            zaehler.set(key, (zaehler.get(key) ?? 0) + 1);
        }

        return [...zaehler.entries()]
            // „Hier" immer zuerst, der Rest alphabetisch — eine Reihenfolge,
            // die sich beim Nachladen nicht umsortiert.
            .sort(([a], [b]) => (a === HIER ? -1 : b === HIER ? 1 : bezeichnung(a).localeCompare(bezeichnung(b))))
            .map(([key, anzahl]) => ({ key, anzahl, label: bezeichnung(key) }));
    }, [items, gelieferteNamen, sourceLabels, localLabel]);

    const gefiltert = useMemo(
        () =>
            items.filter((item) => {
                if (nurUngelesen && item.isRead) {
                    return false;
                }

                return herkunft === null || schluessel(item) === herkunft;
            }),
        [items, nurUngelesen, herkunft],
    );

    /**
     * Ein Filter, der nichts übrig lässt, sieht aus wie ein leerer Posteingang.
     * Deshalb sagt die Liste es in diesem Fall anders.
     */
    const leerText =
        nurUngelesen || herkunft !== null
            ? 'Nichts, das zu diesem Filter passt'
            : 'Keine Benachrichtigungen';

    return (
        <div className={cn('space-y-4', className)}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    <div className="rounded-lg bg-muted p-2">
                        <Inbox className="h-5 w-5 text-muted-foreground" />
                    </div>
                    <div>
                        <h1 className="text-xl font-semibold leading-tight">{title}</h1>
                        <p className="text-xs text-muted-foreground">
                            {/* Zwei Zahlen, die verschiedene Dinge zaehlen:
                                Zeilen sind gebuendelt, ungelesene nicht. „206
                                ungelesen von 102 Zeilen" las sich wie ein
                                Rechenfehler. */}
                            {unreadCount > 0
                                ? `${items.length} Zeile${items.length === 1 ? '' : 'n'} · ${unreadCount} ungelesen`
                                : `${items.length} Zeile${items.length === 1 ? '' : 'n'}, alles gelesen`}
                        </p>
                    </div>
                </div>

                <Button variant="outline" disabled={loading} onClick={() => void refresh()} title="Neu laden">
                    <RefreshCw className={cn('mr-1.5 h-3.5 w-3.5', loading && 'animate-spin')} />
                    Aktualisieren
                </Button>
            </div>

            {unreachable.length > 0 && (
                <p className="rounded-md border border-amber-600/40 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                    {unreachable.length === 1
                        ? `${unreachable[0]} antwortet gerade nicht — von dort fehlt hier etwas.`
                        : `${unreachable.join(', ')} antworten gerade nicht — von dort fehlt hier etwas.`}
                </p>
            )}

            {children}

            <div className="flex flex-wrap items-center gap-2">
                <Button
                    variant={nurUngelesen ? 'primary' : 'outline'}
                    onClick={() => setNurUngelesen((an) => !an)}
                >
                    Nur ungelesen
                </Button>

                {/* Die Herkunfts-Reiter erst ab zwei — bei einer einzigen
                    Herkunft filtert man zwischen „alles" und „alles". */}
                {herkuenfte.length > 1 && (
                    <>
                        <span className="mx-1 h-5 w-px bg-border" aria-hidden />

                        <Button variant={herkunft === null ? 'primary' : 'outline'} onClick={() => setHerkunft(null)}>
                            Alle Systeme
                        </Button>

                        {herkuenfte.map(({ key, anzahl, label }) => (
                            <Button
                                key={key}
                                variant={herkunft === key ? 'primary' : 'outline'}
                                onClick={() => setHerkunft(herkunft === key ? null : key)}
                            >
                                {label}
                                <Badge variant="outline" className="ml-1.5">
                                    {anzahl}
                                </Badge>
                            </Button>
                        ))}
                    </>
                )}
            </div>

            <NotificationList
                items={gefiltert}
                busy={loading}
                emptyLabel={leerText}
                formatTime={formatTime}
                onOpen={onOpen}
                isDuplicate={isDuplicate}
                renderActions={renderActions}
                renderDetails={renderDetails}
                sourceLabel={(item) => (item.source ? bezeichnung(item.source) : null)}
                // Eine Zeile aus einem anderen System liegt dort, nicht hier.
                // Ein Haken hier könnte sie drüben nicht erreichen.
                isRemote={(item) => Boolean(item.source)}
                onResolve={(item) => markRead(item.ids)}
                onDelete={(ids) => remove(ids)}
                onMarkAllRead={() => markAllRead()}
                onDeleteAll={() => removeAll()}
            />
        </div>
    );
}

export default NotificationPage;
