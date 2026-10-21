import { ArrowUpRight, Bell } from 'lucide-react';
import { cn } from './ui/utils';

/**
 * „Die Einstellungen wohnen jetzt woanders."
 *
 * ## Warum es diese Komponente gibt
 *
 * Ab dem 21.10.2026 werden Benachrichtigungen an EINER Stelle gepflegt — im
 * AI Brain, das die Arten aller angebundenen Systeme dort zusammenführt
 * (#520). Die Masken in Manager, CRM und Verwaltung entfallen damit.
 *
 * Der Menüeintrag bleibt aber stehen und zeigt hierher. Der Grund ist
 * derselbe, aus dem der Posteingang einen Direktlink bekam: Wer die
 * Einstellung sucht, sucht sie dort, wo er sie kennt. Ein Menüpunkt, der
 * verschwindet, hinterlässt jemanden, der klickt und nichts findet — und
 * drei Klicks ins Leere sind schlimmer als einer zum Ziel.
 *
 * ## Was hier NICHT passiert
 *
 * Kein Weiterleiten. Eine Seite, die von selbst in ein anderes System
 * springt, nimmt dem Menschen die Entscheidung ab und wirft ihn aus seinem
 * Arbeitsfluss — womöglich mitten in etwas anderem. Der Satz erklärt, der
 * Klick gehört ihm.
 */
export interface NotificationSettingsMovedProps {
    /**
     * Vollständige Adresse der Maske im Brain.
     *
     * Kommt vom Produkt, nicht von hier: Sie steht dort in der Konfiguration
     * (`ai-brain-bridge.base_url`), und ein fest eingebauter Rumpf wäre in
     * einer anderen Umgebung — Entwicklung, Abnahme — die falsche Adresse.
     */
    href: string;
    /** Name dieses Systems, für den erklärenden Satz. */
    systemName?: string;
    className?: string;
}

export function NotificationSettingsMoved({
    href,
    systemName,
    className,
}: NotificationSettingsMovedProps) {
    const wessen = systemName ? `von ${systemName}` : 'dieses Systems';

    return (
        <div className={cn('rounded-lg border p-6', className)}>
            <div className="flex items-start gap-3">
                <Bell className="mt-0.5 h-5 w-5 shrink-0 text-muted-foreground" />
                <div className="space-y-3">
                    <div className="space-y-1">
                        <p className="font-medium">Benachrichtigungen werden in AI Brain eingestellt</p>
                        <p className="text-sm text-muted-foreground">
                            Dort stehen die Arten {wessen} zusammen mit denen der anderen Systeme — eine
                            Stelle statt vier. Gespeichert wird weiterhin hier; nur die Maske ist
                            umgezogen.
                        </p>
                    </div>

                    <a
                        href={href}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-md border border-input px-3 py-1.5',
                            'text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground',
                            'focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                        )}
                    >
                        In AI Brain einstellen
                        <ArrowUpRight className="h-3.5 w-3.5" />
                    </a>
                </div>
            </div>
        </div>
    );
}

export default NotificationSettingsMoved;
