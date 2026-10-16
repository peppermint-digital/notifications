import { Bell } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';
import { NotificationList, type NotificationListProps } from './NotificationList';
import { cn } from './ui/utils';

/**
 * Die Glocke: ein Abzeichen und darunter dieselbe Liste wie im Posteingang.
 *
 * „Dieselbe" ist hier wörtlich gemeint. Im Manager gab es die Liste zweimal —
 * die Glocke kannte 15 der 39 Arten, der Posteingang alle, und eine
 * Mail-Warnung sah in der Glocke aus wie eine Erinnerung. Zwei Ansichten
 * derselben Daten driften, sobald es zwei sind.
 *
 * ## Kein Popover-Paket
 *
 * Bewusst von Hand statt über Radix: Das wäre eine weitere Peer-Abhängigkeit,
 * die in vier Produkten in vier Fassungen vorliegen kann — und für ein
 * Aufklappen, das sich bei einem Klick daneben wieder schließt, lohnt sie
 * nicht. Der Kalender zieht Radix nur, weil sein Auswahlfeld es wirklich
 * braucht.
 */
export interface NotificationBellProps extends Omit<NotificationListProps, 'onMarkAllRead' | 'onDeleteAll' | 'className'> {
    unreadCount: number;
    onMarkAllRead?: () => void | Promise<void>;

    /** Ab wann „99+" statt einer Zahl steht. */
    maxBadge?: number;

    /** Fuß der Klappe — etwa ein Link auf die vollständige Übersicht. */
    footer?: ReactNode;

    /** Ausrichtung der Klappe zum Knopf. */
    align?: 'left' | 'right';

    className?: string;
    panelClassName?: string;
}

export function NotificationBell({
    unreadCount,
    maxBadge = 99,
    footer,
    align = 'right',
    className,
    panelClassName,
    ...liste
}: NotificationBellProps) {
    const [offen, setOffen] = useState(false);
    const huelle = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!offen) {
            return;
        }

        function beiKlickDaneben(ereignis: MouseEvent): void {
            if (huelle.current && !huelle.current.contains(ereignis.target as Node)) {
                setOffen(false);
            }
        }

        function beiEscape(ereignis: KeyboardEvent): void {
            if (ereignis.key === 'Escape') {
                setOffen(false);
            }
        }

        document.addEventListener('mousedown', beiKlickDaneben);
        document.addEventListener('keydown', beiEscape);

        return () => {
            document.removeEventListener('mousedown', beiKlickDaneben);
            document.removeEventListener('keydown', beiEscape);
        };
    }, [offen]);

    return (
        <div ref={huelle} className={cn('relative', className)}>
            <button
                type="button"
                className={cn(
                    'relative inline-flex h-9 w-9 items-center justify-center rounded-md',
                    'transition-colors hover:bg-accent hover:text-accent-foreground',
                    'focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                )}
                aria-label={
                    unreadCount > 0
                        ? `Benachrichtigungen, ${unreadCount} ungelesen`
                        : 'Benachrichtigungen'
                }
                aria-expanded={offen}
                onClick={() => setOffen((war) => !war)}
            >
                <Bell className="h-5 w-5" />

                {unreadCount > 0 && (
                    <span
                        className={cn(
                            'absolute -top-0.5 -right-0.5 inline-flex min-w-[1.125rem] items-center justify-center',
                            'rounded-full bg-destructive px-1 py-0.5 text-[0.625rem] leading-none font-semibold',
                            'text-destructive-foreground',
                        )}
                    >
                        {unreadCount > maxBadge ? `${maxBadge}+` : unreadCount}
                    </span>
                )}
            </button>

            {offen && (
                <div
                    className={cn(
                        'absolute z-50 mt-2 w-[min(26rem,calc(100vw-2rem))] rounded-lg border bg-popover p-3 shadow-lg',
                        align === 'right' ? 'right-0' : 'left-0',
                        panelClassName,
                    )}
                >
                    {/* Die Liste wird scrollbar gehalten, nicht gekürzt: Was
                        der Server geliefert hat, ist schon gedeckelt — hier
                        noch einmal zu schneiden hiesse, Meldungen zu
                        verstecken, die nirgends sonst auftauchen. */}
                    <div className="max-h-[70vh] overflow-y-auto">
                        <NotificationList {...liste} />
                    </div>

                    {footer && <div className="mt-3 border-t pt-3 text-sm">{footer}</div>}
                </div>
            )}
        </div>
    );
}

export default NotificationBell;
