/**
 * Die Symbole und Farben der Benachrichtigungsarten — an EINER Stelle.
 *
 * Die Vorgeschichte, die diese Datei begründet: Im Manager lieferte der Server
 * für jede der 39 Arten einen Symbolnamen. Die Glocke kannte davon 15, alles
 * andere fiel stillschweigend auf das Glocken-Symbol zurück — eine
 * Mail-Warnung sah aus wie eine Erinnerung. Beim Übertragen nach React kam
 * genau dieser Fehler zurück: Die Glocke bekam wieder ihre eigene 15er-Liste,
 * der Posteingang die vollständige.
 *
 * Deshalb: **wer eine weitere Ansicht baut, importiert von hier.**
 *
 * Ein Symbolname, den die Zuordnung nicht kennt, bekommt bewusst weiterhin
 * einen Rückfall statt eines Fehlers — eine Benachrichtigung ohne passendes
 * Symbol ist besser als keine.
 */
import {
    AlarmClock,
    AlertTriangle,
    AtSign,
    Bell,
    BookOpen,
    Calendar,
    CalendarCheck,
    CalendarClock,
    CalendarPlus,
    CalendarX,
    CheckCircle,
    Clock,
    FileText,
    FolderCog,
    FolderMinus,
    FolderPlus,
    FolderX,
    Inbox,
    Lock,
    type LucideIcon,
    Mail,
    MailWarning,
    Megaphone,
    MessageCircle,
    PackageCheck,
    PhoneMissed,
    RefreshCw,
    Server,
    ShieldAlert,
    Thermometer,
    Trash2,
    Unlock,
    UserMinus,
    UserPlus,
    XCircle,
} from 'lucide-react';

const ICONS: Record<string, LucideIcon> = {
    'alarm-clock': AlarmClock,
    'alert-triangle': AlertTriangle,
    'at-sign': AtSign,
    bell: Bell,
    'book-open': BookOpen,
    calendar: Calendar,
    'calendar-check': CalendarCheck,
    'calendar-clock': CalendarClock,
    'calendar-plus': CalendarPlus,
    'calendar-x': CalendarX,
    'check-circle': CheckCircle,
    clock: Clock,
    'file-text': FileText,
    'folder-cog': FolderCog,
    'folder-minus': FolderMinus,
    'folder-plus': FolderPlus,
    'folder-x': FolderX,
    inbox: Inbox,
    lock: Lock,
    mail: Mail,
    'mail-warning': MailWarning,
    megaphone: Megaphone,
    'message-circle': MessageCircle,
    'package-check': PackageCheck,
    'phone-missed': PhoneMissed,
    'refresh-cw': RefreshCw,
    server: Server,
    'shield-alert': ShieldAlert,
    thermometer: Thermometer,
    'trash-2': Trash2,
    unlock: Unlock,
    'user-minus': UserMinus,
    'user-plus': UserPlus,
    'x-circle': XCircle,
};

export function notificationIcon(name: string): LucideIcon {
    return ICONS[name] ?? Bell;
}

/** Die Symbolnamen, die diese Zuordnung kennt — für Prüfungen im Produkt. */
export function bekannteSymbole(): string[] {
    return Object.keys(ICONS);
}

/*
 * Die Klassennamen stehen AUSGESCHRIEBEN da.
 *
 * Tailwind scannt den Quelltext nach Zeichenketten; ein zusammengesetztes
 * `text-${color}-600` landet nie im Build und die Farbe fehlt stumm. Dass das
 * hier wie unnötige Wiederholung aussieht, ist der Preis dafür, dass es
 * funktioniert.
 *
 * Damit Tailwind sie im aufnehmenden Produkt überhaupt sieht, braucht dessen
 * `app.css` eine Zeile:
 *
 *     @source '../../node_modules/@peppermint-digital/notifications/dist';
 */
const TEXT: Record<string, string> = {
    blue: 'text-blue-600 dark:text-blue-400',
    cyan: 'text-cyan-600 dark:text-cyan-400',
    gray: 'text-gray-600 dark:text-gray-400',
    green: 'text-green-600 dark:text-green-400',
    indigo: 'text-indigo-600 dark:text-indigo-400',
    orange: 'text-orange-600 dark:text-orange-400',
    purple: 'text-purple-600 dark:text-purple-400',
    red: 'text-red-600 dark:text-red-400',
    teal: 'text-teal-600 dark:text-teal-400',
    yellow: 'text-yellow-600 dark:text-yellow-400',
};

const SURFACE: Record<string, string> = {
    blue: 'bg-blue-50 border-blue-100 dark:bg-blue-950 dark:border-blue-900',
    cyan: 'bg-cyan-50 border-cyan-100 dark:bg-cyan-950 dark:border-cyan-900',
    gray: 'bg-gray-50 border-gray-100 dark:bg-gray-950 dark:border-gray-900',
    green: 'bg-green-50 border-green-100 dark:bg-green-950 dark:border-green-900',
    indigo: 'bg-indigo-50 border-indigo-100 dark:bg-indigo-950 dark:border-indigo-900',
    orange: 'bg-orange-50 border-orange-100 dark:bg-orange-950 dark:border-orange-900',
    purple: 'bg-purple-50 border-purple-100 dark:bg-purple-950 dark:border-purple-900',
    red: 'bg-red-50 border-red-100 dark:bg-red-950 dark:border-red-900',
    teal: 'bg-teal-50 border-teal-100 dark:bg-teal-950 dark:border-teal-900',
    yellow: 'bg-yellow-50 border-yellow-100 dark:bg-yellow-950 dark:border-yellow-900',
};

export function notificationTextClass(color: string): string {
    return TEXT[color] ?? TEXT.gray!;
}

export function notificationSurfaceClass(color: string): string {
    return SURFACE[color] ?? SURFACE.gray!;
}

/** Die Farbworte mit einer Schriftfarbe. */
export function bekannteFarben(): string[] {
    return Object.keys(TEXT);
}

/**
 * Die Farbworte mit einer Fläche.
 *
 * Getrennt von {@link bekannteFarben}, damit ein Test beide Listen
 * vergleichen kann: Fehlt einer Farbe die Fläche oder die Schrift, fällt
 * diese Hälfte still auf Grau zurück, und die Zeile sieht halb eingefärbt
 * aus. Das sieht man erst, wenn eine Meldung dieser Farbe auftritt — bei
 * einer seltenen Art also womöglich nie.
 */
export function bekannteFlaechenfarben(): string[] {
    return Object.keys(SURFACE);
}
