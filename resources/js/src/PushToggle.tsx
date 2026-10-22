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

    /*
     * Der Kasten nennt jetzt die SCHRITTE, nicht nur die Zuständigkeit.
     *
     * Anlass: Volker sass am 22.10.2026 darin fest. Der alte Text sagte
     * richtig, dass die Erlaubnis dem Browser gehoert — und liess ihn dann
     * allein. Zwei Dinge fehlten:
     *
     * 1. Das NEULADEN. {@see usePush} prueft `Notification.permission` nur
     *    beim Einhaengen. Wer die Erlaubnis im Browser umstellt und
     *    zurueckkommt, sieht denselben Kasten und haelt den Fix fuer
     *    wirkungslos.
     * 2. Dass unser SCHALTER dagegen nichts kann. Die naheliegende Reaktion
     *    ist „aus und wieder an" — und bei `denied` zeigt der Browser gar
     *    keine Abfrage mehr, es passiert also nichts.
     *
     * Und wie man ueberhaupt hineingeraet: Chrome sperrt eine Herkunft von
     * selbst, wenn Anfragen mehrfach scheitern oder weggeklickt werden. Genau
     * das hat unser eigener Fehler (#1068) ausgeloest — die Leute haben
     * tagelang auf einen Schalter geklickt, der nicht konnte.
     */
    if (status === 'abgelehnt') {
        return (
            <Kasten className={className} ton="warnung" symbol={ShieldAlert} titel="Der Browser blockiert Benachrichtigungen für diese Seite">
                <>
                    Unser Schalter kann das nicht aufheben — solange die Sperre steht, fragt der Browser gar nicht
                    mehr. So kommst du heraus:
                    <ol className="mt-2 ml-4 list-decimal space-y-1">
                        <li>Auf das Schloss-Symbol links in der Adresszeile klicken.</li>
                        <li>Bei „Benachrichtigungen" auf <strong>Zulassen</strong> stellen oder die Berechtigung zurücksetzen.</li>
                        <li>
                            <strong>Diese Seite neu laden</strong> — ohne das bleibt dieser Hinweis stehen, auch wenn
                            die Sperre schon weg ist.
                        </li>
                    </ol>
                    <p className="mt-2">
                        Der Browser sperrt eine Seite auch von selbst, wenn die Anfrage mehrmals scheitert oder
                        weggeklickt wird — das muss nichts sein, was du bewusst entschieden hast.
                    </p>
                </>
            </Kasten>
        );
    }

    /*
     * Die abgelaufene Sitzung steht VOR „kein Schlüssel" — sie war bis zum
     * 22.10.2026 in jenem Kasten mit drin (Bug #1068) und hat zwei Menschen
     * glauben lassen, das Brain liefere keinen Schlüssel. Der Satz nannte
     * eine Ursache, an der sie nichts ändern konnten, und verschwieg die,
     * an der sie alles ändern konnten.
     */
    if (status === 'nicht-angemeldet') {
        return (
            <Kasten className={className} ton="warnung" symbol={ShieldAlert} titel="Deine Anmeldung ist abgelaufen">
                Deshalb lässt sich hier gerade nichts einstellen — mit Push oder dem Schlüssel hat es nichts zu tun.
                Seite neu laden, neu anmelden, dann greift der Schalter wieder.
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
                {/* Ein `div` und kein `p`: Seit der gesperrten Erlaubnis
                    stehen hier auch eine Liste und mehrere Absaetze, und ein
                    Blockelement in einem `p` ist ungueltiges HTML — der
                    Browser bricht die Verschachtelung dann selbst auf, und
                    die Abstaende sitzen falsch. Die uebrigen Kaesten geben
                    nur Text, der sieht hier genauso aus. */}
                <div className="mt-1 text-muted-foreground">{children}</div>
            </div>
        </div>
    );
}

export default PushToggle;
