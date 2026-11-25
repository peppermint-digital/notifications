import { BellRing, Loader2, ShieldAlert } from 'lucide-react';
import { useState } from 'react';
import { Button } from './ui/Button';
import { cn } from './ui/utils';
import { usePush, type PushRoutes } from './usePush';

/**
 * Der Hinweis „Push ist auf diesem Gerät aus" — in jedem Produkt derselbe.
 *
 * Er steht, solange auf DIESEM Gerät keine Push-Nachricht ankommt: kein Abo,
 * keine Erlaubnis, oder ein Abo, das der Server nicht mehr kennt (der
 * Push-Dienst hat es verworfen). So merkt jeder sofort, warum nichts kommt,
 * statt es Tage später zu vermuten.
 *
 * Entstanden aus dem einmaligen Banner im Manager zur Umstellung auf den
 * eigenen Versand (pm #10191). Der merkte sich das Wegklicken für immer —
 * danach erfuhr niemand mehr, dass sein Gerät still ist (pm #10206).
 *
 * ## Wann er NICHT erscheint
 *
 * - Der Browser kann kein Push (iPhone ohne Home-Bildschirm, alte Browser):
 *   Es gibt nichts, was man hier tun könnte.
 * - Kein Schlüssel, abgelaufene Anmeldung, noch am Prüfen: Darum kümmern
 *   sich der Schalter in den Einstellungen bzw. die Anmeldung.
 * - „Später" wurde in DIESER Sitzung gedrückt. Nur die Sitzung — beim
 *   nächsten Öffnen des Browsers steht er wieder da.
 *
 * Ob das Produkt Push überhaupt anbietet, entscheidet das Produkt: Es hängt
 * den Hinweis nur ein, wenn es einen Versand hat.
 */
export interface PushHinweisProps {
    routes?: PushRoutes;
    className?: string;
    /** Schlüssel im sessionStorage für „Später". Vorgabe: `peppermint.push-hinweis.spaeter` */
    merkname?: string;
}

const VORGABE_MERKNAME = 'peppermint.push-hinweis.spaeter';

/**
 * Wo die gesperrte Erlaubnis wieder aufgeht.
 *
 * Im Browser über das Schloss in der Adresszeile. In der App vom
 * Home-Bildschirm gibt es keine Adresszeile — dort steht die Erlaubnis in den
 * Einstellungen des Handys, und die heißen bei iOS und Android verschieden.
 */
export type SperrOrt = 'browser' | 'ios-app' | 'android-app';

export function sperrOrt(): SperrOrt {
    if (typeof window === 'undefined') {
        return 'browser';
    }

    const alsApp =
        (typeof window.matchMedia === 'function' && window.matchMedia('(display-mode: standalone)').matches) ||
        (navigator as Navigator & { standalone?: boolean }).standalone === true;

    if (!alsApp) {
        return 'browser';
    }

    return /iPhone|iPad|iPod/.test(navigator.userAgent) ? 'ios-app' : 'android-app';
}

const ANLEITUNG: Record<SperrOrt, string> = {
    browser: 'Schloss-Symbol in der Adresszeile → Benachrichtigungen auf „Zulassen“ → Seite neu laden.',
    'ios-app': 'Einstellungen → Mitteilungen → diese App → „Mitteilungen erlauben“ einschalten, dann die App neu öffnen.',
    'android-app': 'Lange auf das App-Symbol tippen → App-Info → Benachrichtigungen → zulassen, dann die App neu öffnen.',
};

function spaeterGedrueckt(merkname: string): boolean {
    try {
        return window.sessionStorage.getItem(merkname) === '1';
    } catch {
        return false;
    }
}

export function PushHinweis({ routes, className, merkname = VORGABE_MERKNAME }: PushHinweisProps) {
    const { status, busy, error, subscribe } = usePush(routes);
    const [zu, setZu] = useState(() => spaeterGedrueckt(merkname));

    if (zu || (status !== 'nicht-abonniert' && status !== 'abgelehnt')) {
        return null;
    }

    const spaeter = () => {
        try {
            window.sessionStorage.setItem(merkname, '1');
        } catch {
            // Ohne Speicher kommt der Hinweis auf der nächsten Seite wieder — harmlos.
        }
        setZu(true);
    };

    const gesperrt = status === 'abgelehnt';
    const Symbol = gesperrt ? ShieldAlert : BellRing;

    return (
        <div
            className={cn(
                'flex flex-wrap items-center gap-x-3 gap-y-2 border-b px-4 py-2 text-sm',
                gesperrt ? 'border-warning-border bg-warning-subtle' : 'bg-muted/60',
                className,
            )}
            role="status"
        >
            <Symbol
                className={cn('size-4 shrink-0', gesperrt ? 'text-warning' : 'text-primary')}
                aria-hidden
            />
            {/* Mindestbreite, damit die Knoepfe am Handy UNTER den Text
                umbrechen, statt ihn auf eine Wortspalte zu quetschen. */}
            <div className="min-w-0 flex-1 basis-60">
                {gesperrt ? (
                    <>
                        <span className="font-medium">
                            {sperrOrt() === 'browser'
                                ? 'Der Browser blockiert Push-Benachrichtigungen für diese Seite.'
                                : 'Push-Benachrichtigungen sind für diese App blockiert.'}
                        </span>{' '}
                        {ANLEITUNG[sperrOrt()]}
                    </>
                ) : (
                    <>
                        <span className="font-medium">Push-Benachrichtigungen sind auf diesem Gerät aus.</span>{' '}
                        Einschalten, damit Meldungen auch ankommen, wenn die Seite zu ist.
                    </>
                )}
                {error && <span className="block text-destructive">{error}</span>}
            </div>
            <div className="ml-auto flex shrink-0 items-center gap-2">
                {!gesperrt && (
                    <Button variant="primary" onClick={() => void subscribe()} disabled={busy}>
                        {busy && <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />}
                        Einschalten
                    </Button>
                )}
                <Button variant="ghost" onClick={spaeter}>
                    Später
                </Button>
            </div>
        </div>
    );
}

export default PushHinweis;
