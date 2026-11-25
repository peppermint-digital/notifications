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
    Globe,
    Inbox,
    Lightbulb,
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
    globe: Globe,
    inbox: Inbox,
    lightbulb: Lightbulb,
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
 * Die Farben der Arten kommen aus dem Theme des Produkts (pm #10236, F18).
 *
 * Jedes Farbwort hat eine Nummer — blue 1, cyan 2, gray 3, green 4, indigo 5,
 * orange 6, purple 7, red 8, teal 9, yellow 10 — und liest drei Tokens:
 * `--kategorie-N` (Symbol), `--kategorie-N-flaeche`, `--kategorie-N-rand`.
 * Der Wert hinter dem Komma ist der bisherige Tailwind-Wert (600/400 fuer das
 * Symbol, 50/100 bzw. 950/900 fuer die Flaeche): Ein Produkt ohne diese
 * Tokens sieht genau das, was es vorher sah. `color:` sagt Tailwind, dass
 * `text-[…]` und `border-[…]` eine Farbe meinen, keine Groesse oder Breite.
 *
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
    blue: 'text-[color:var(--kategorie-1,oklch(54.6%_0.245_262.881))] dark:text-[color:var(--kategorie-1,oklch(70.7%_0.165_254.624))]',
    cyan: 'text-[color:var(--kategorie-2,oklch(60.9%_0.126_221.723))] dark:text-[color:var(--kategorie-2,oklch(78.9%_0.154_211.53))]',
    gray: 'text-[color:var(--kategorie-3,oklch(44.6%_0.03_256.802))] dark:text-[color:var(--kategorie-3,oklch(70.7%_0.022_261.325))]',
    green: 'text-[color:var(--kategorie-4,oklch(62.7%_0.194_149.214))] dark:text-[color:var(--kategorie-4,oklch(79.2%_0.209_151.711))]',
    indigo: 'text-[color:var(--kategorie-5,oklch(51.1%_0.262_276.966))] dark:text-[color:var(--kategorie-5,oklch(67.3%_0.182_276.935))]',
    orange: 'text-[color:var(--kategorie-6,oklch(64.6%_0.222_41.116))] dark:text-[color:var(--kategorie-6,oklch(75%_0.183_55.934))]',
    purple: 'text-[color:var(--kategorie-7,oklch(55.8%_0.288_302.321))] dark:text-[color:var(--kategorie-7,oklch(71.4%_0.203_305.504))]',
    red: 'text-[color:var(--kategorie-8,oklch(57.7%_0.245_27.325))] dark:text-[color:var(--kategorie-8,oklch(70.4%_0.191_22.216))]',
    teal: 'text-[color:var(--kategorie-9,oklch(60%_0.118_184.704))] dark:text-[color:var(--kategorie-9,oklch(77.7%_0.152_181.912))]',
    yellow: 'text-[color:var(--kategorie-10,oklch(68.1%_0.162_75.834))] dark:text-[color:var(--kategorie-10,oklch(85.2%_0.199_91.936))]',
};

const SURFACE: Record<string, string> = {
    blue: 'bg-[color:var(--kategorie-1-flaeche,oklch(97%_0.014_254.604))] border-[color:var(--kategorie-1-rand,oklch(93.2%_0.032_255.585))] dark:bg-[color:var(--kategorie-1-flaeche,oklch(28.2%_0.091_267.935))] dark:border-[color:var(--kategorie-1-rand,oklch(37.9%_0.146_265.522))]',
    cyan: 'bg-[color:var(--kategorie-2-flaeche,oklch(98.4%_0.019_200.873))] border-[color:var(--kategorie-2-rand,oklch(95.6%_0.045_203.388))] dark:bg-[color:var(--kategorie-2-flaeche,oklch(30.2%_0.056_229.695))] dark:border-[color:var(--kategorie-2-rand,oklch(39.8%_0.07_227.392))]',
    gray: 'bg-[color:var(--kategorie-3-flaeche,oklch(98.5%_0.002_247.839))] border-[color:var(--kategorie-3-rand,oklch(96.7%_0.003_264.542))] dark:bg-[color:var(--kategorie-3-flaeche,oklch(13%_0.028_261.692))] dark:border-[color:var(--kategorie-3-rand,oklch(21%_0.034_264.665))]',
    green: 'bg-[color:var(--kategorie-4-flaeche,oklch(98.2%_0.018_155.826))] border-[color:var(--kategorie-4-rand,oklch(96.2%_0.044_156.743))] dark:bg-[color:var(--kategorie-4-flaeche,oklch(26.6%_0.065_152.934))] dark:border-[color:var(--kategorie-4-rand,oklch(39.3%_0.095_152.535))]',
    indigo: 'bg-[color:var(--kategorie-5-flaeche,oklch(96.2%_0.018_272.314))] border-[color:var(--kategorie-5-rand,oklch(93%_0.034_272.788))] dark:bg-[color:var(--kategorie-5-flaeche,oklch(25.7%_0.09_281.288))] dark:border-[color:var(--kategorie-5-rand,oklch(35.9%_0.144_278.697))]',
    orange: 'bg-[color:var(--kategorie-6-flaeche,oklch(98%_0.016_73.684))] border-[color:var(--kategorie-6-rand,oklch(95.4%_0.038_75.164))] dark:bg-[color:var(--kategorie-6-flaeche,oklch(26.6%_0.079_36.259))] dark:border-[color:var(--kategorie-6-rand,oklch(40.8%_0.123_38.172))]',
    purple: 'bg-[color:var(--kategorie-7-flaeche,oklch(97.7%_0.014_308.299))] border-[color:var(--kategorie-7-rand,oklch(94.6%_0.033_307.174))] dark:bg-[color:var(--kategorie-7-flaeche,oklch(29.1%_0.149_302.717))] dark:border-[color:var(--kategorie-7-rand,oklch(38.1%_0.176_304.987))]',
    red: 'bg-[color:var(--kategorie-8-flaeche,oklch(97.1%_0.013_17.38))] border-[color:var(--kategorie-8-rand,oklch(93.6%_0.032_17.717))] dark:bg-[color:var(--kategorie-8-flaeche,oklch(25.8%_0.092_26.042))] dark:border-[color:var(--kategorie-8-rand,oklch(39.6%_0.141_25.723))]',
    teal: 'bg-[color:var(--kategorie-9-flaeche,oklch(98.4%_0.014_180.72))] border-[color:var(--kategorie-9-rand,oklch(95.3%_0.051_180.801))] dark:bg-[color:var(--kategorie-9-flaeche,oklch(27.7%_0.046_192.524))] dark:border-[color:var(--kategorie-9-rand,oklch(38.6%_0.063_188.416))]',
    yellow: 'bg-[color:var(--kategorie-10-flaeche,oklch(98.7%_0.026_102.212))] border-[color:var(--kategorie-10-rand,oklch(97.3%_0.071_103.193))] dark:bg-[color:var(--kategorie-10-flaeche,oklch(28.6%_0.066_53.813))] dark:border-[color:var(--kategorie-10-rand,oklch(42.1%_0.095_57.708))]',
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
