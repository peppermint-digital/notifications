import { Inbox } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { cn } from './ui/utils';

/**
 * Der Posteingangs-Knopf für die Kopfzeile — in jedem Produkt derselbe.
 *
 * ## Warum es ihn gibt
 *
 * Das Postfach hing zuerst nur in der Umschaltleiste. Die ist eine Klappe,
 * die man aufziehen muss — und gleichzeitig war die Glocke aus der Kopfzeile
 * verschwunden. Ergebnis: oben war gar nichts mehr zu sehen, und der
 * Posteingang nur noch über die Adresszeile erreichbar.
 *
 * Vorbild ist der Peppermint Manager: ein Symbol in der Kopfzeile, ein
 * Abzeichen mit der Zahl, ein Klick auf eine Seite. Keine Klappe, kein
 * Aufziehen.
 *
 * ## Warum kein Dropdown
 *
 * Die Glocke mit Klappliste gibt es als {@link NotificationBell} weiter — für
 * ein Produkt, das allein läuft und keine gemeinsame Seite hat. Sobald es
 * eine Seite gibt, ist die Klappe die schlechtere Wahl: Sie zeigt einen
 * Ausschnitt, hat eigene Aktionen und läuft gegenüber der Seite auseinander.
 * Genau das war der Zustand, aus dem dieses Paket entstanden ist.
 *
 * ## Woher die Zahl kommt
 *
 * Aus einer eigenen, billigen Abfrage und nicht aus dem Feed: Der Knopf
 * hängt an JEDER Seite, die Liste nur dort, wo jemand hinsieht. Wer hier
 * den ganzen Feed lädt, bezahlt ihn auf jeder Seite.
 *
 * ## Die Vorgaben zeigen auf den GEMEINSAMEN Posteingang (AI Brain #1152)
 *
 * Hier stand bis zum 29.10.2026 `/notifications/inbox` und
 * `/api/inbox/unread-count` — die produkteigene Meldungsseite und die Zahl
 * der ungelesenen Meldungen DIESES Systems. Das war richtig, als es die
 * gemeinsame Seite noch nicht gab.
 *
 * Seit es sie gibt, war es zweimal falsch: Der Klick führte auf einen
 * Ausschnitt, und die Zahl sprach über etwas anderes als die Seite, die
 * sie aufmacht — dort stehen auch Aufgaben, Belege und imkr-Zeilen aus vier
 * Systemen. Beides zeigt jetzt auf den gemeinsamen Posteingang und auf
 * `GET /posteingang/ungelesen` aus `peppermint/inbox`.
 *
 * Ein Produkt, das OHNE das Inbox-Paket läuft, setzt beide Werte selbst auf
 * seine Meldungsseite zurück. Die Vorgabe ist der Normalfall, nicht die
 * einzige Möglichkeit.
 */
export interface InboxButtonProps {
    /** Wohin der Klick führt. Vorgabe: `/posteingang` */
    href?: string;

    /** Woher die Zahl kommt. Vorgabe: `/posteingang/ungelesen` */
    countUrl?: string;

    /** Vorbefüllung aus Server-Props — spart die erste Abfrage. */
    initialCount?: number;

    /** Abfragetakt in Millisekunden. 0 schaltet das Nachladen ab. */
    pollMs?: number;

    /**
     * Navigieren. Ohne Angabe ein gewöhnlicher Link.
     *
     * Inertia-Anwendungen reichen hier ihren Router herein — sonst lädt der
     * Klick die ganze Seite neu und das Produkt verliert seinen Zustand.
     */
    onNavigate?: (href: string) => void;

    label?: string;
    className?: string;
}

const VORGABE_HREF = '/posteingang';
const VORGABE_ZAEHLER = '/posteingang/ungelesen';

export function InboxButton({
    href = VORGABE_HREF,
    countUrl = VORGABE_ZAEHLER,
    initialCount = 0,
    pollMs = 60_000,
    onNavigate,
    label = 'Posteingang',
    className,
}: InboxButtonProps) {
    const [anzahl, setAnzahl] = useState(initialCount);

    const holen = useCallback(async (): Promise<void> => {
        try {
            const antwort = await fetch(countUrl, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!antwort.ok) {
                return;
            }

            const zahl = Number((await antwort.json()).unread_count);

            if (Number.isFinite(zahl)) {
                setAnzahl(zahl);
            }
        } catch {
            // Ein Netzaussetzer lässt die letzte bekannte Zahl stehen. Eine
            // Null wäre die falsche Aussage — sie hieße „alles gelesen".
        }
    }, [countUrl]);

    useEffect(() => {
        void holen();

        if (pollMs <= 0) {
            return;
        }

        const takt = setInterval(() => void holen(), pollMs);

        return () => clearInterval(takt);
    }, [holen, pollMs]);

    // Dreistellig passt nicht auf ein Abzeichen dieser Größe, und die genaue
    // Zahl ist ab da ohnehin keine Hilfe mehr.
    const text = anzahl > 99 ? '99+' : String(anzahl);

    const beschriftung = anzahl > 0 ? `${label} — ${anzahl} ungelesen` : label;

    return (
        <a
            href={href}
            aria-label={beschriftung}
            title={beschriftung}
            onClick={(e) => {
                if (!onNavigate || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) {
                    // Mit gedrückter Taste gehört der Klick dem Browser: Wer
                    // „in neuem Tab öffnen" meint, soll das bekommen.
                    return;
                }

                e.preventDefault();
                onNavigate(href);
            }}
            className={cn(
                'group relative inline-flex h-9 w-9 items-center justify-center rounded-md',
                'text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground',
                className,
            )}
        >
            <Inbox className="h-5 w-5 opacity-80 group-hover:opacity-100" />

            {anzahl > 0 && (
                <span className="absolute -top-1 -right-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-destructive px-1 text-xs text-white">
                    {text}
                </span>
            )}
        </a>
    );
}

export default InboxButton;
