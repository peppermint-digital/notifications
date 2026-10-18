import { BellOff, Check, CheckCheck, ExternalLink, Eye, EyeOff, Trash2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { useMemo, useState } from 'react';
import { notificationIcon, notificationSurfaceClass, notificationTextClass } from './icons';
import type { NotificationItem } from './types';
import { Badge } from './ui/Badge';
import { Button } from './ui/Button';
import { cn } from './ui/utils';

/**
 * Die Benachrichtigungsliste — dieselbe Ansicht in jedem Produkt.
 *
 * Herkunft: der Posteingangs-Reiter des Peppermint Managers (#3488). Dort ist
 * sie entstanden, weil die alte Glockenliste dieselbe Sache dreimal zeigte;
 * hier ist sie der gemeinsame Baustein.
 *
 * Zwei Eigenschaften, die sie von einer gewöhnlichen Liste unterscheiden:
 *
 * 1. **Gebündelt** — gleichartige ungelesene Meldungen am selben Gegenstand
 *    bilden eine Zeile mit Zähler. Das passiert auf dem Server; hier wird nur
 *    `bundleCount` angezeigt und auf `ids` gehandelt.
 * 2. **Entdoppelt** — was das Produkt anderswo ohnehin schon zeigt, ist
 *    standardmäßig ausgeblendet. Ausgeblendet, nicht weggeworfen: Die Zahl
 *    steht darüber und ein Klick holt es zurück.
 *
 * ## Was das Produkt beisteuert
 *
 * Alles, was nur dort Sinn ergibt, kommt als Funktion herein statt als
 * Sonderfall hineingebaut zu werden: die Navigation (Inertia oder nicht),
 * zusätzliche Knöpfe (im Manager der Rückruf bei einem verpassten Anruf),
 * Zusatzzeilen (Aufgabentitel, Projektname) und die Frage, was als Doppelung
 * gilt.
 */
export interface NotificationListProps {
    items: NotificationItem[];

    /** Eine Zeile öffnen. Ohne Angabe wird `item.link` gesetzt. */
    onOpen?: (item: NotificationItem) => void;

    /** Erledigen — in der Form, die zur Quelle der Zeile passt. */
    onResolve: (item: NotificationItem) => void | Promise<void>;
    onDelete: (ids: Array<number | string>) => void | Promise<void>;
    onMarkAllRead?: () => void | Promise<void>;
    onDeleteAll?: () => void | Promise<void>;

    /**
     * Gilt diese Zeile als Doppelung zu etwas, das anderswo schon steht?
     *
     * Im Manager: Meldungen zu Aufgaben, die in den Aufgaben-Reitern daneben
     * stehen. Ohne Angabe wird nichts ausgeblendet.
     */
    isDuplicate?: (item: NotificationItem) => boolean;

    /** Zusätzliche Knöpfe rechts an einer Zeile. */
    renderActions?: (item: NotificationItem) => ReactNode;

    /** Zusatzzeilen zwischen Titel und Nachricht. */
    renderDetails?: (item: NotificationItem) => ReactNode;

    /**
     * Aus welchem System die Zeile stammt — als Wort, nicht als Kuerzel.
     *
     * Ohne Angabe steht keine Herkunft an der Zeile. Das war lange der einzige
     * Zustand und ist es fuer ein Produkt ohne AI Brain weiterhin: Wenn alles
     * von hier kommt, ist „von hier" keine Information.
     *
     * Sobald gespiegelt wird, kehrt sich das um — dann steht in jedem System
     * alles, und ohne Herkunft ist nicht mehr erkennbar, wo etwas passiert ist.
     * Gereicht wird trotzdem eine BESCHRIFTUNG und kein Kuerzel: Was
     * `peppermint-manager` fuer einen Leser heisst, weiss das Produkt.
     */
    sourceLabel?: (item: NotificationItem) => string | null;

    /**
     * Gehoert diese Zeile einem ANDEREN System?
     *
     * Dann fehlen „gelesen" und „loeschen". Nicht aus Vorsicht: Die Zeile
     * liegt drueben, und ein Haken hier kann sie dort nicht erreichen. Ein
     * Knopf, der nichts bewirkt, ist schlimmer als keiner — und genau das
     * war der Fehler der kopierten Fassung, wo man eine Kopie als gelesen
     * markierte und das Original ungelesen blieb.
     *
     * Der Verweis bleibt: Er fuehrt in das System, dem die Zeile gehoert.
     */
    isRemote?: (item: NotificationItem) => boolean;

    /** Einen Zeitpunkt als Text. Ohne Angabe die rohe ISO-Zeichenkette. */
    formatTime?: (iso: string) => string;

    busy?: boolean;
    emptyLabel?: string;
    className?: string;
}

export function NotificationList({
    items,
    onOpen,
    onResolve,
    onDelete,
    onMarkAllRead,
    onDeleteAll,
    isDuplicate,
    renderActions,
    renderDetails,
    sourceLabel,
    isRemote,
    formatTime = (iso) => iso,
    busy = false,
    emptyLabel = 'Keine Benachrichtigungen',
    className,
}: NotificationListProps) {
    const [zeigeDoppelte, setZeigeDoppelte] = useState(false);

    const istDoppelt = useMemo(() => isDuplicate ?? ((): boolean => false), [isDuplicate]);

    const doppelte = useMemo(() => items.filter(istDoppelt), [items, istDoppelt]);

    const sichtbare = useMemo(
        () => (zeigeDoppelte ? items : items.filter((item) => !istDoppelt(item))),
        [items, zeigeDoppelte, istDoppelt],
    );

    // „Neu" und „Früher" statt einer durchlaufenden Liste: Der Unterschied
    // zwischen „das habe ich noch nicht gesehen" und „das steht hier noch
    // herum" ist der einzige, auf den es beim Überfliegen ankommt.
    const neue = useMemo(() => sichtbare.filter((item) => !item.isRead), [sichtbare]);
    const frueher = useMemo(() => sichtbare.filter((item) => item.isRead), [sichtbare]);

    function oeffnen(item: NotificationItem): void {
        if (onOpen) {
            onOpen(item);

            return;
        }

        if (!item.link) {
            return;
        }

        // Erst als gesehen verbuchen, dann navigieren — umgekehrt ist die
        // Seite weg, bevor die Quittung raus ist.
        if (!item.isRead) {
            void onResolve(item);
        }

        window.location.assign(item.link);
    }

    const UmschaltSymbol = zeigeDoppelte ? EyeOff : Eye;
    const hatZeilen = items.length > 0;

    return (
        <div className={cn('space-y-3', className)}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                {doppelte.length > 0 ? (
                    <p className="text-xs text-muted-foreground">
                        {doppelte.length} Meldung{doppelte.length === 1 ? '' : 'en'} zu Dingen, die anderswo schon stehen,{' '}
                        {zeigeDoppelte ? 'werden mit angezeigt' : 'sind ausgeblendet'}.
                    </p>
                ) : (
                    <span />
                )}

                <div className="flex flex-wrap items-center gap-2">
                    {doppelte.length > 0 && (
                        <Button onClick={() => setZeigeDoppelte((gezeigt) => !gezeigt)}>
                            <UmschaltSymbol className="mr-1.5 h-3.5 w-3.5" />
                            {zeigeDoppelte ? 'Doppelungen ausblenden' : 'Doppelungen zeigen'}
                        </Button>
                    )}
                    {hatZeilen && onMarkAllRead && (
                        <Button variant="outline" disabled={busy} onClick={() => void onMarkAllRead()}>
                            <CheckCheck className="mr-1.5 h-3.5 w-3.5" />
                            Alle gelesen
                        </Button>
                    )}
                    {hatZeilen && onDeleteAll && (
                        <Button variant="destructive" disabled={busy} onClick={() => void onDeleteAll()}>
                            <Trash2 className="mr-1.5 h-3.5 w-3.5" />
                            Alle löschen
                        </Button>
                    )}
                </div>
            </div>

            {sichtbare.length === 0 ? (
                <div className="rounded-lg border border-dashed p-8 text-center">
                    <BellOff className="mx-auto mb-3 h-10 w-10 text-muted-foreground/50" />
                    <p className="text-sm text-muted-foreground">
                        {items.length === 0 ? emptyLabel : 'Nichts Neues — alles Übrige steht schon anderswo'}
                    </p>
                </div>
            ) : (
                <>
                    {neue.length > 0 && (
                        <div className="space-y-2">
                            <p className="text-xs font-semibold text-muted-foreground">Neu ({neue.length})</p>
                            {neue.map((item) => (
                                <Zeile
                                    key={`${item.source ?? 'self'}-${item.id}`}
                                    item={item}
                                    frisch
                                    doppelt={istDoppelt(item)}
                                    busy={busy}
                                    onOpen={oeffnen}
                                    onResolve={onResolve}
                                    onDelete={onDelete}
                                    renderActions={renderActions}
                                    renderDetails={renderDetails}
                                    sourceLabel={sourceLabel}
                                    isRemote={isRemote}
                                    formatTime={formatTime}
                                />
                            ))}
                        </div>
                    )}

                    {frueher.length > 0 && (
                        <div className="space-y-2">
                            <p className="text-xs font-semibold text-muted-foreground">Früher ({frueher.length})</p>
                            {frueher.map((item) => (
                                <Zeile
                                    key={`${item.source ?? 'self'}-${item.id}`}
                                    item={item}
                                    frisch={false}
                                    doppelt={istDoppelt(item)}
                                    busy={busy}
                                    onOpen={oeffnen}
                                    onResolve={onResolve}
                                    onDelete={onDelete}
                                    renderActions={renderActions}
                                    renderDetails={renderDetails}
                                    sourceLabel={sourceLabel}
                                    isRemote={isRemote}
                                    formatTime={formatTime}
                                />
                            ))}
                        </div>
                    )}
                </>
            )}
        </div>
    );
}

interface ZeileProps {
    item: NotificationItem;
    frisch: boolean;
    doppelt: boolean;
    busy: boolean;
    onOpen: (item: NotificationItem) => void;
    onResolve: (item: NotificationItem) => void | Promise<void>;
    onDelete: (ids: Array<number | string>) => void | Promise<void>;
    renderActions?: (item: NotificationItem) => ReactNode;
    renderDetails?: (item: NotificationItem) => ReactNode;
    sourceLabel?: (item: NotificationItem) => string | null;
    isRemote?: (item: NotificationItem) => boolean;
    formatTime: (iso: string) => string;
}

function Zeile({
    item,
    frisch,
    doppelt,
    busy,
    onOpen,
    onResolve,
    onDelete,
    renderActions,
    renderDetails,
    sourceLabel,
    isRemote,
    formatTime,
}: ZeileProps) {
    const Symbol = notificationIcon(item.icon);
    const herkunft = sourceLabel?.(item) ?? null;
    const fremd = isRemote?.(item) ?? false;

    return (
        <div
            className={cn(
                'group rounded-lg border p-3',
                frisch ? notificationSurfaceClass(item.color) : 'transition-colors hover:bg-muted/30',
            )}
        >
            <div className="flex items-start gap-3">
                <div className={cn('mt-0.5 shrink-0 rounded-full p-1.5', frisch ? notificationSurfaceClass(item.color) : 'bg-muted')}>
                    <Symbol className={cn('h-4 w-4', frisch ? notificationTextClass(item.color) : 'text-muted-foreground')} />
                </div>

                <div className="min-w-0 flex-1 cursor-pointer" onClick={() => onOpen(item)}>
                    <div className="flex flex-wrap items-center gap-2">
                        <p className={cn('font-medium hover:underline', frisch ? notificationTextClass(item.color) : 'truncate')}>
                            {item.title}
                        </p>
                        {/* Der Zähler nur dort, wo er etwas sagt: Eine Zeile,
                            hinter der eine Meldung steht, braucht kein „1×". */}
                        {item.bundleCount > 1 && <Badge>{item.bundleCount}×</Badge>}
                        {doppelt && <Badge variant="outline">steht auch anderswo</Badge>}
                        {herkunft && <Badge variant="outline">{herkunft}</Badge>}
                    </div>

                    {renderDetails?.(item)}

                    {item.message && <p className="mt-1 line-clamp-2 text-xs text-muted-foreground">{item.message}</p>}

                    <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                        <span>{formatTime(item.createdAt)}</span>
                        {item.triggeredBy && <span className="truncate">· von {item.triggeredBy}</span>}
                        {item.typeLabel && <span className="truncate">· {item.typeLabel}</span>}
                        {/* Das rohe `source` steht hier bewusst NICHT.
                            Es ist ein technisches Feld — die Quelle einer
                            Zeile, an der die Aktionen hängen („erledigen"
                            statt „gelesen") und später das Produkt, aus dem
                            eine gespiegelte Meldung stammt. Als Text neben
                            der Beschriftung las es sich doppelt:
                            „· Erinnerung · reminder".

                            Wer die Herkunft zeigen will, gibt `sourceLabel`
                            mit — dann steht sie als Abzeichen oben, in dem
                            Wort, das der Leser kennt. */}
                    </div>
                </div>

                <div className="flex shrink-0 items-center gap-1">
                    {renderActions?.(item)}

                    {!item.isRead && !fremd && (
                        <Button
                            icon
                            className="text-blue-600 hover:bg-blue-50 dark:text-blue-400 dark:hover:bg-blue-950"
                            title="Als gelesen markieren"
                            disabled={busy}
                            onClick={(event) => {
                                event.stopPropagation();
                                void onResolve(item);
                            }}
                        >
                            <Check className="h-4 w-4" />
                        </Button>
                    )}

                    {!fremd && (
                    <Button
                        icon
                        variant="destructive"
                        className="opacity-0 transition-opacity group-hover:opacity-100"
                        title="Löschen"
                        disabled={busy}
                        onClick={(event) => {
                            event.stopPropagation();
                            void onDelete(item.ids);
                        }}
                    >
                        <Trash2 className="h-4 w-4" />
                    </Button>
                    )}

                    {item.link && (
                        <Button
                            icon
                            className="opacity-0 transition-opacity group-hover:opacity-100"
                            title="Öffnen"
                            disabled={busy}
                            onClick={(event) => {
                                event.stopPropagation();
                                onOpen(item);
                            }}
                        >
                            <ExternalLink className="h-4 w-4" />
                        </Button>
                    )}
                </div>
            </div>
        </div>
    );
}

export default NotificationList;
