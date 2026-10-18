import { Bell, BellOff, Loader2, ShieldAlert, Smartphone } from 'lucide-react';
import { Button } from './ui/Button';
import { cn } from './ui/utils';
import { usePush, type PushRoutes } from './usePush';

/**
 * Der Push-Schalter — in jedem Produkt derselbe.
 *
 * Bis zum 18.10.2026 gab es ihn zweimal (AI Brain, Manager) und in CRM und
 * Verwaltung gar nicht. Dort stand in der Einstellungsmaske trotzdem eine
 * Push-Spalte, die man stellen konnte und die nichts tat — das ist schlimmer
 * als keine Spalte.
 *
 * ## Was der Schalter NICHT ist
 *
 * Er ist keine Einstellung, sondern eine Erlaubnis für DIESES Gerät. Welche
 * Arten per Push kommen sollen, steht in der Matrix daneben und gilt für
 * alle Geräte. Deshalb steht hier auch kein „gespeichert" — es gibt nichts
 * zu speichern, der Browser hält das Abo.
 */
export interface PushToggleProps {
    routes?: PushRoutes;
    className?: string;
}

export function PushToggle({ routes, className }: PushToggleProps) {
    const { status, busy, error, subscribe, unsubscribe } = usePush(routes);

    if (status === 'pruefe') {
        return <div className={cn('h-20 animate-pulse rounded-lg bg-muted', className)} />;
    }

    if (status === 'nicht-moeglich') {
        return (
            <Kasten className={className} ton="neutral" symbol={Smartphone} titel="Dieser Browser kann keine Push-Benachrichtigungen">
                Auf dem iPhone geht es erst, wenn die Seite zum Home-Bildschirm hinzugefügt wurde (Teilen → Zum
                Home-Bildschirm). Auf Android und am Rechner funktioniert es direkt im Browser.
            </Kasten>
        );
    }

    if (status === 'abgelehnt') {
        return (
            <Kasten className={className} ton="warnung" symbol={ShieldAlert} titel="Benachrichtigungen sind für diese Seite blockiert">
                Das lässt sich hier nicht aufheben — die Erlaubnis gehört dem Browser. Sie steht in den
                Seiteneinstellungen, meist hinter dem Schloss-Symbol neben der Adresse.
            </Kasten>
        );
    }

    if (status === 'kein-schluessel') {
        return (
            <Kasten className={className} ton="warnung" symbol={ShieldAlert} titel="Push ist hier gerade nicht eingerichtet">
                Es kommt kein Schlüssel von AI Brain. Ohne ihn kann dieser Browser nicht abonnieren — in der Glocke
                steht trotzdem alles.
            </Kasten>
        );
    }

    const abonniert = status === 'abonniert';

    return (
        <div className={cn('space-y-2', className)}>
            <div
                className={cn(
                    'flex flex-wrap items-center justify-between gap-3 rounded-lg border p-4',
                    abonniert && 'border-green-600/30 bg-green-50 dark:bg-green-950/30',
                )}
            >
                <div className="flex items-start gap-3">
                    {abonniert ? (
                        <Bell className="mt-0.5 h-5 w-5 shrink-0 text-green-600 dark:text-green-400" />
                    ) : (
                        <BellOff className="mt-0.5 h-5 w-5 shrink-0 text-muted-foreground" />
                    )}
                    <div>
                        <p className="font-medium">
                            {abonniert ? 'Dieses Gerät bekommt Push' : 'Dieses Gerät bekommt kein Push'}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {abonniert
                                ? 'Auch wenn der Browser geschlossen ist. Gilt nur für dieses Gerät — jedes weitere einzeln.'
                                : 'Einschalten, um Meldungen auch dann zu bekommen, wenn die Seite nicht offen ist.'}
                        </p>
                    </div>
                </div>

                <Button
                    variant={abonniert ? 'outline' : 'primary'}
                    disabled={busy}
                    onClick={() => void (abonniert ? unsubscribe() : subscribe())}
                >
                    {busy && <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />}
                    {abonniert ? 'Abbestellen' : 'Einschalten'}
                </Button>
            </div>

            {error && <p className="text-sm text-destructive">{error}</p>}
        </div>
    );
}

interface KastenProps {
    titel: string;
    children: React.ReactNode;
    symbol: typeof Bell;
    ton: 'neutral' | 'warnung';
    className?: string;
}

function Kasten({ titel, children, symbol: Symbol, ton, className }: KastenProps) {
    return (
        <div
            className={cn(
                'flex items-start gap-3 rounded-lg border p-4',
                ton === 'warnung' && 'border-amber-600/30 bg-amber-50 dark:bg-amber-950/30',
                className,
            )}
        >
            <Symbol
                className={cn(
                    'mt-0.5 h-5 w-5 shrink-0',
                    ton === 'warnung' ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground',
                )}
            />
            <div className="text-sm">
                <p className="font-medium">{titel}</p>
                <p className="mt-1 text-muted-foreground">{children}</p>
            </div>
        </div>
    );
}

export default PushToggle;
