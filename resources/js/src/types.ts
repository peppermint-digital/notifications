/**
 * Die Form, in der eine Benachrichtigung beim Browser ankommt.
 *
 * Eine Form fuer alle Produkte und alle Ansichten. Im Manager gab es diese
 * Form zweimal — die Glocke kannte 15 der 39 Arten, der Posteingang alle; eine
 * Mail-Warnung sah in der Glocke aus wie eine Erinnerung. Wer eine dritte
 * Ansicht baut, nimmt diese Typen, statt sich eigene zu schreiben.
 */

/** Eine Benachrichtigungsart, wie das Produkt sie angemeldet hat. */
export interface NotificationType {
    key: string;
    label: string;
    /** Name aus dem Symbol-Satz, z.B. `user-plus`. Kein fertiges Symbol. */
    icon: string;
    /** Farbwort, z.B. `blue`. Die Klassen stehen im Frontend-Paket. */
    color: string;
    [weitere: string]: unknown;
}

/**
 * Eine Zeile in der Liste.
 *
 * `ids` statt `id` ist der Kern: Gleichartige ungelesene Meldungen am selben
 * Gegenstand bilden EINE Zeile mit Zaehler, aber jeder Datensatz dahinter
 * bleibt einzeln ansprechbar. Sonst wuerde „gelesen" auf einer gebuendelten
 * Zeile nur eine der Meldungen treffen, und der Zaehler im Kopf widerspraeche
 * der Liste darunter.
 */
export interface NotificationItem {
    id: number | string;
    /** Alle Datensaetze hinter dieser Zeile. Ohne Buendelung genau einer. */
    ids: Array<number | string>;
    /** Wie viele Einzelmeldungen zusammengefasst wurden (>= 1). */
    bundleCount: number;

    type: string;
    typeLabel: string;
    title: string;
    message: string | null;
    icon: string;
    color: string;
    link: string | null;

    triggeredBy?: string | null;
    triggeredById?: number | string | null;

    /**
     * Woran die Meldung haengt. Damit blendet eine Ansicht aus, was ihre
     * uebrigen Bereiche ohnehin schon zeigen.
     */
    subjectType?: string | null;
    subjectId?: number | string | null;

    isRead: boolean;
    /** ISO-8601. */
    createdAt: string;

    /**
     * Aus welchem Produkt die Meldung stammt. Leer heisst: aus diesem.
     * Gefuellt, sobald ueber AI Brain gespiegelt wird.
     */
    source?: string | null;
}

export interface NotificationFeed {
    items: NotificationItem[];
    /**
     * Die Zahl am Abzeichen zaehlt EINZELMELDUNGEN, nicht Zeilen: Sie soll
     * sagen, wie viel ungesehen ist — nicht, wie viele Kacheln daraus wurden.
     */
    unreadCount: number;
}
