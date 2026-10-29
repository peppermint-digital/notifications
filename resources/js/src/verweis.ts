/**
 * Einen Verweis öffnen — im Router, wenn er hierher führt, sonst im Browser.
 *
 * ## Warum das ins Paket gehört
 *
 * Weil es in jedem Produkt dieselben sechs Zeilen sind, und weil sie dort
 * schon dreimal unterschiedlich geschrieben wurden (AI Brain #1151):
 *
 * - `pages/notifications/inbox.tsx` prüft `/^https?:\/\//` und nimmt dann
 *   `window.location.assign` — richtig, aber in vier Dateien abgeschrieben.
 * - `pages/posteingang/index.tsx` entscheidet an `source` statt am Verweis.
 *   Das trifft heute zu und ist trotzdem der falsche Maßstab: Ob ein Klick
 *   die Anwendung verlässt, steht in der Adresse, nicht in der Herkunft.
 * - `components/notification-bell.tsx` nimmt immer `router.visit` — und das
 *   ist seit dem 29.10.2026 falsch, weil fremde Zeilen jetzt eine absolute
 *   Adresse tragen. Der Router würde sie als Seite DIESER Anwendung
 *   anfordern.
 *
 * ## Die Regel
 *
 * Entschieden wird am Ziel, nicht an der Herkunft:
 *
 * | Verweis | Weg |
 * |---|---|
 * | fehlt | nichts |
 * | anderer Ursprung (`https://brain…/bugs/1122`) | Browser — hier endet die Anwendung |
 * | anderes Schema (`mailto:`, `tel:`) | Browser — das ist nicht einmal eine Seite |
 * | gleicher Ursprung, auch absolut | Router, wenn es einen gibt |
 * | relativ (`/bugs/1122`) | Router, wenn es einen gibt |
 *
 * Dass ein Verweis auf den EIGENEN Ursprung auch absolut sein darf und
 * trotzdem in den Router geht, ist kein Detail: Mehrere Produkte bauen ihre
 * Verweise seit immer absolut (`config('app.url')`), und ein Klick auf eine
 * eigene Zeile soll den Zustand der Anwendung nicht wegwerfen.
 *
 * ## Ohne Router
 *
 * Dann der Browser. Ein Paket darf nicht voraussetzen, dass die einbindende
 * Anwendung eine SPA ist — und ein Klick, der nichts tut, weil kein Router
 * übergeben wurde, wäre der schlechteste Ausgang.
 */
export function oeffneVerweis(
    verweis: string | null | undefined,
    navigieren?: (ziel: string) => void,
): void {
    if (typeof verweis !== 'string' || verweis.trim() === '') {
        return;
    }

    const ziel = verweis.trim();

    if (verlaesstDieAnwendung(ziel)) {
        window.location.assign(ziel);

        return;
    }

    if (navigieren) {
        navigieren(ziel);

        return;
    }

    window.location.assign(ziel);
}

/**
 * Führt dieser Verweis aus der laufenden Anwendung heraus?
 *
 * Gemessen am Ursprung und nicht an der Schreibweise. `URL` löst dabei auch
 * das protokollrelative `//host/pfad` und einen absoluten Verweis auf den
 * eigenen Host richtig auf — beides würde eine Prüfung auf `https?://`
 * falsch einordnen.
 *
 * Lässt sich der Verweis nicht auflösen, gilt er als hiesig. Das ist die
 * vorsichtige Richtung: Der Router kommt mit einem kaputten Pfad zurecht
 * und zeigt einen Fehler in der Anwendung; `window.location` verlässt sie
 * vorher.
 */
function verlaesstDieAnwendung(verweis: string): boolean {
    if (typeof window === 'undefined') {
        // Serverseitiges Rendern: Es gibt keinen Ursprung, gegen den man
        // vergleichen könnte.
        return false;
    }

    try {
        return new URL(verweis, window.location.href).origin !== window.location.origin;
    } catch {
        return false;
    }
}

export default oeffneVerweis;
