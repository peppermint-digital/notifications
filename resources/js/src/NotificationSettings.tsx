import { RotateCcw } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { notificationIcon, notificationSurfaceClass, notificationTextClass } from './icons';
import { Badge } from './ui/Badge';
import { Button } from './ui/Button';
import { cn } from './ui/utils';

/**
 * Die Einstellungsmaske: wer bekommt was, auf welchem Weg (#7994).
 *
 * Eine Matrix — eine Zeile je Benachrichtigungsart, drei Felder darin für
 * Push, Anwendung und E-Mail. Wie überall in diesem Paket liegt sie hier und
 * nicht im Produkt, damit es sie nicht viermal gibt.
 *
 * ## Was „folgt der Vorgabe" heißt
 *
 * Ein Feld ohne eigene Entscheidung zeigt den Wert, der gilt — aber blass,
 * mit dem Hinweis „Vorgabe". Das ist nicht dasselbe wie dieselbe Stufe selbst
 * gewählt zu haben: Wer der Vorgabe folgt, folgt ihr auch weiter, wenn sie
 * sich ändert. Deshalb gibt es je Zeile einen Knopf, der auf „Vorgabe"
 * zurücksetzt, statt nur die Stufe umzustellen.
 *
 * ## Warum E-Mail anders aussieht als die anderen beiden
 *
 * Push und Anwendung kosten eine Zeile und einen Ton; eine E-Mail kostet eine
 * E-Mail. Für E-Mail steht die Vorgabe deshalb auf „Zusammenfassung". Das
 * steht so auch in der Maske — sonst klickt jemand auf „Sofort" und lernt es
 * erst am nächsten Morgen an seinem Postfach.
 */

export interface PreferenceChannel {
    level: string;
    /** Selbst entschieden? Sonst gilt die Vorgabe. */
    eigen: boolean;
    vorgabe: string;
}

export interface PreferenceRow {
    type: string;
    label: string;
    icon: string;
    color: string;
    channels: Record<string, PreferenceChannel>;
}

export interface PreferenceVocabulary {
    key: string;
    label: string;
}

export interface NotificationSettingsProps {
    /** Vorbefüllung aus Server-Props — spart das erste Laden. */
    initial?: {
        types: PreferenceRow[];
        channels: PreferenceVocabulary[];
        levels: PreferenceVocabulary[];
    };

    /** Vorgabe: `/api/notification-preferences` für beides. */
    routes?: { index?: string; update?: string };

    className?: string;
}

const VORGABE_ROUTEN = {
    index: '/api/notification-preferences',
    update: '/api/notification-preferences',
};

/**
 * Das CSRF-Token — aus dem Kopf oder aus dem Cookie.
 *
 * Dieselbe Begründung wie in `useNotifications`: Inertia-Anwendungen haben oft
 * kein `<meta name="csrf-token">`, und ein leerer Kopf täuscht einen
 * Mechanismus vor, den es nicht gibt.
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

export function NotificationSettings({ initial, routes, className }: NotificationSettingsProps) {
    const adressen = useMemo(() => ({ ...VORGABE_ROUTEN, ...routes }), [routes]);

    const [zeilen, setZeilen] = useState<PreferenceRow[]>(initial?.types ?? []);
    const [wege, setWege] = useState<PreferenceVocabulary[]>(initial?.channels ?? []);
    const [stufen, setStufen] = useState<PreferenceVocabulary[]>(initial?.levels ?? []);
    const [laedt, setLaedt] = useState(!initial);
    const [speichert, setSpeichert] = useState<string | null>(null);
    const [fehler, setFehler] = useState<string | null>(null);

    const laden = useCallback(async (): Promise<void> => {
        try {
            const antwort = await fetch(adressen.index, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!antwort.ok) {
                setFehler('Die Einstellungen konnten nicht geladen werden.');

                return;
            }

            const daten = await antwort.json();

            setZeilen(daten.types ?? []);
            setWege(daten.channels ?? []);
            setStufen(daten.levels ?? []);
            setFehler(null);
        } catch {
            setFehler('Die Einstellungen konnten nicht geladen werden.');
        } finally {
            setLaedt(false);
        }
    }, [adressen.index]);

    useEffect(() => {
        if (!initial) {
            void laden();
        }
        // `initial` ist die Vorbefüllung des ersten Durchlaufs, kein Auslöser.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [laden]);

    /**
     * Eine ganze Zeile speichern, nicht ein Feld.
     *
     * Der Server nimmt die Zeile als Einheit: Eine Zeile, in der alle drei
     * Wege der Vorgabe folgen, wird gelöscht statt mit lauter Leerwerten
     * stehen zu bleiben. Einzelne Felder zu schicken hieße, diese Entscheidung
     * hier nachzubauen.
     */
    async function speichern(zeile: PreferenceRow, aenderung: Record<string, string> | null): Promise<void> {
        if (speichert) {
            return;
        }

        setSpeichert(zeile.type);

        // Alles, was schon selbst entschieden war, bleibt — alles Übrige geht
        // als `null` hinaus, also als „folgt der Vorgabe". `aenderung = null`
        // ist das Zurücksetzen der ganzen Zeile.
        const kanaele: Record<string, string | null> = {};

        for (const k of wege) {
            const stand = zeile.channels[k.key];

            kanaele[k.key] = aenderung?.[k.key] ?? (aenderung && stand?.eigen ? stand.level : null);
        }

        try {
            const antwort = await fetch(adressen.update, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...csrfHeaders(),
                },
                body: JSON.stringify({ type: zeile.type, channels: kanaele }),
            });

            if (!antwort.ok) {
                setFehler('Gespeichert wurde nichts — die Antwort war nicht in Ordnung.');

                return;
            }

            const daten = await antwort.json();

            // Die Antwort bringt die ganze Matrix zurück. Örtlich zu ändern
            // wäre schneller, aber dann stünde hier eine zweite Vorstellung
            // davon, was gilt — und die läge irgendwann daneben.
            setZeilen(daten.types ?? []);
            setFehler(null);
        } catch {
            setFehler('Gespeichert wurde nichts — die Verbindung kam nicht zustande.');
        } finally {
            setSpeichert(null);
        }
    }

    const hatEigene = (zeile: PreferenceRow): boolean => wege.some((k) => zeile.channels[k.key]?.eigen);

    if (laedt) {
        return (
            <div className={cn('space-y-2', className)}>
                {[0, 1, 2, 3].map((i) => (
                    <div key={i} className="h-14 animate-pulse rounded-lg bg-muted" />
                ))}
            </div>
        );
    }

    return (
        <div className={cn('space-y-3', className)}>
            {fehler && (
                <p className="rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive">
                    {fehler}
                </p>
            )}

            {zeilen.length === 0 ? (
                <div className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
                    Dieses System meldet noch keine Benachrichtigungsarten an.
                </div>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[34rem] border-separate border-spacing-y-1 text-sm">
                        <thead>
                            <tr className="text-xs text-muted-foreground">
                                <th className="px-2 pb-1 text-left font-medium">Art</th>
                                {wege.map((weg) => (
                                    <th key={weg.key} className="px-2 pb-1 text-left font-medium">
                                        {weg.label}
                                    </th>
                                ))}
                                <th className="px-2 pb-1" />
                            </tr>
                        </thead>
                        <tbody>
                            {zeilen.map((zeile) => {
                                const Symbol = notificationIcon(zeile.icon);
                                const beschaeftigt = speichert === zeile.type;

                                return (
                                    <tr key={zeile.type} className={cn(beschaeftigt && 'opacity-60')}>
                                        <td className="rounded-l-lg bg-muted/30 px-2 py-2">
                                            <div className="flex items-center gap-2">
                                                <span className={cn('rounded-full p-1', notificationSurfaceClass(zeile.color))}>
                                                    <Symbol className={cn('h-3.5 w-3.5', notificationTextClass(zeile.color))} />
                                                </span>
                                                <span className="font-medium">{zeile.label}</span>
                                            </div>
                                        </td>

                                        {wege.map((weg) => {
                                            const stand = zeile.channels[weg.key];

                                            return (
                                                <td key={weg.key} className="bg-muted/30 px-2 py-2">
                                                    <div className="flex items-center gap-1.5">
                                                        <select
                                                            className={cn(
                                                                'h-8 rounded-md border border-input bg-background px-2 text-sm',
                                                                !stand?.eigen && 'text-muted-foreground',
                                                            )}
                                                            value={stand?.level ?? ''}
                                                            disabled={beschaeftigt}
                                                            onChange={(e) => void speichern(zeile, { [weg.key]: e.target.value })}
                                                            aria-label={`${zeile.label} — ${weg.label}`}
                                                        >
                                                            {stufen.map((stufe) => (
                                                                <option key={stufe.key} value={stufe.key}>
                                                                    {stufe.label}
                                                                </option>
                                                            ))}
                                                        </select>

                                                        {/* „Vorgabe" sagt mehr als ein blasser Wert allein:
                                                            Hier ist nichts entschieden, und eine Änderung der
                                                            Vorgabe schlägt weiter durch. */}
                                                        {!stand?.eigen && (
                                                            <Badge variant="outline" className="shrink-0">
                                                                Vorgabe
                                                            </Badge>
                                                        )}
                                                    </div>
                                                </td>
                                            );
                                        })}

                                        <td className="rounded-r-lg bg-muted/30 px-2 py-2 text-right">
                                            {hatEigene(zeile) && (
                                                <Button
                                                    icon
                                                    title="Auf die Vorgabe zurücksetzen"
                                                    disabled={beschaeftigt}
                                                    onClick={() => void speichern(zeile, null)}
                                                >
                                                    <RotateCcw className="h-4 w-4" />
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

export default NotificationSettings;
