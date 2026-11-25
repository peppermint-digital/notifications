import { Inbox } from 'lucide-react';
import { cn } from './ui/utils';
import { POSTEINGANG_GEAENDERT, usePosteingangZahl } from './usePosteingangZahl';

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
    /**
     * Die Zahl von aussen, aus {@link usePosteingangZahl} (pm #10233).
     *
     * Fuer ein Produkt, das sie an zweiter Stelle braucht (am Telefon ein
     * Menue statt des Knopfes) und deshalb selbst zaehlt. Dann fragt der
     * Knopf nicht noch einmal. Ohne Angabe zaehlt er wie bisher.
     */
    count?: number;
}

const VORGABE_HREF = '/posteingang';
const VORGABE_ZAEHLER = '/posteingang/ungelesen';

export { POSTEINGANG_GEAENDERT };

export function InboxButton({
    href = VORGABE_HREF,
    countUrl = VORGABE_ZAEHLER,
    initialCount = 0,
    pollMs = 60_000,
    onNavigate,
    label = 'Posteingang',
    className,
    count,
}: InboxButtonProps) {
    // Reicht das Produkt die Zahl selbst herein (`count`), zaehlt der Knopf
    // nicht noch einmal — sonst fragten Knopf und Telefon-Menue denselben
    // Endpunkt doppelt ab (pm #10233).
    const selbstGezaehlt = usePosteingangZahl({ countUrl, initialCount, pollMs, aktiv: count === undefined });
    const anzahl = count ?? selbstGezaehlt;

    // Dreistellig passt nicht auf ein Abzeichen dieser Größe, und die genaue
    // Zahl ist ab da ohnehin keine Hilfe mehr.
    const text = anzahl > 99 ? '99+' : String(anzahl);

    /*
     * „in allen Systemen" gehört dazu (AI Brain #1152).
     *
     * Die Zahl ist der Bestand über alle Arten und alle Systeme, die Reiter
     * auf der Seite dahinter zeigen dagegen, was in der Liste steht — je Art
     * und System auf 50 gedeckelt. Beides ist richtig und beantwortet eine
     * andere Frage; ohne diesen Zusatz las es sich als Widerspruch.
     *
     * Gemessen am 29.10.2026: 4623 am Symbol gegen Reiter mit 9/29/50/112/50.
     * Der erste Eindruck war „das kann nicht stimmen" — und das ist genau
     * der Eindruck, aus dem #1096 entstanden ist.
     */
    const beschriftung =
        anzahl > 0 ? `${label} — ${anzahl} ungelesen in allen Systemen` : label;

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
