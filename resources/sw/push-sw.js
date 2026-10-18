/**
 * Der Service Worker fuer Web-Push — in jedem Produkt derselbe.
 *
 * ## Warum er ueber eine Route kommt und nicht als Datei im Produkt liegt
 *
 * Dieselbe Begruendung wie bei der Umschaltleiste der Bruecke: Eine kopierte
 * Datei veraltet in dem Moment, in dem sich das Paket aendert, und niemand
 * merkt es. So traegt jedes Produkt die Fassung, die seine Paketversion
 * mitbringt.
 *
 * ## Warum er so wenig tut
 *
 * Anzeigen und oeffnen, mehr nicht. Caching und Offline-Betrieb gehoeren in
 * einen eigenen Worker — ein Worker, der beides macht, faellt bei jedem
 * Caching-Fehler auch fuer die Benachrichtigungen aus.
 *
 * ## Die Nutzlast
 *
 * Kommt seit #7993 immer von AI Brain und hat immer dieselbe Form:
 * `{ title, body, url, tag }`. Fehlt etwas, wird angezeigt, was da ist — eine
 * Benachrichtigung ohne Text ist besser als keine.
 */

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    if (!event.data) {
        return;
    }

    let nutzlast = {};

    try {
        nutzlast = event.data.json();
    } catch {
        // Kein JSON: dann ist der Rohtext die Nachricht. Lieber eine Meldung
        // ohne Titel als gar keine.
        nutzlast = { body: event.data.text() };
    }

    const titel = nutzlast.title || 'Benachrichtigung';

    event.waitUntil(
        self.registration.showNotification(titel, {
            body: nutzlast.body || '',
            icon: nutzlast.icon || '/favicon.ico',
            badge: nutzlast.badge || '/favicon.ico',
            tag: nutzlast.tag || undefined,
            // Gleiche Marke = ersetzen statt stapeln, aber den Menschen
            // trotzdem erneut aufmerksam machen. Ohne `renotify` taeuscht
            // eine ersetzte Meldung Stille vor.
            renotify: Boolean(nutzlast.tag),
            data: { url: nutzlast.url || '/' },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const ziel = event.notification.data?.url || '/';

    event.waitUntil(
        (async () => {
            const fenster = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

            // Ein schon offenes Fenster auf demselben Weg bekommt den Fokus,
            // statt ein zweites aufzumachen. Wer zehn Meldungen anklickt,
            // haette sonst zehn Reiter.
            for (const client of fenster) {
                try {
                    const offen = new URL(client.url);
                    const gewuenscht = new URL(ziel, client.url);

                    if (offen.origin === gewuenscht.origin && offen.pathname === gewuenscht.pathname) {
                        await client.focus();

                        return;
                    }
                } catch {
                    // Eine Adresse, die sich nicht lesen laesst, wird
                    // uebersprungen — nicht zum Abbruch erklaert.
                }
            }

            if (self.clients.openWindow) {
                await self.clients.openWindow(ziel);
            }
        })(),
    );
});
