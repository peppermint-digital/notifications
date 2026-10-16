# peppermint/notifications

Benachrichtigungs-Kern für Laravel. Eine Meldung je Person, ein Vokabular je
Produkt, Zustellung über Glocke und Web-Push — zentral über AI Brain oder für
sich allein.

Dazu `@peppermint-digital/notifications` aus demselben Repository: die
Oberfläche, damit die Liste in jedem Produkt gleich aussieht.

> **Stand:** N1 (Gerüst, Adoption, Typ-Register), N2 (Zustellung, Bündelung,
> Mengen-Zugriff) und N3 (Oberfläche). Die Spiegelung über AI Brain (N7) und
> die Einstellungen pro Person (N9) folgen. Die Schritte stehen als Aufgaben im
> AI-Brain-Projekt `notifications`.

## Warum es das Paket gibt

Alle vier Peppermint-Produkte hatten eine eigene `notifications`-Tabelle **und**
eine eigene Glocke — derselbe Fall wie bei Aufgaben und Kalender. Gemessen am
16.10.2026:

| | Zeilen | ungelesen | letzte 30 Tage | Push-Abos | Vokabular |
|---|---|---|---|---|---|
| AI Brain | 17.356 | 16.322 (94 %) | 7.301 | 0 | eine Klasse für alles |
| Peppermint Manager | 5.019 | 1.241 (25 %) | 1.157 | 19 | 28 von 39 Arten benutzt |
| Peppermint Verwaltung | 227 | 217 (96 %) | 26 | – | 5 Klassen |
| Peppermint CRM | 0 | 0 | 0 | – | – |

Die 94 % im Brain sind der eigentliche Befund: Das System wird nicht gelesen,
weil dieselbe Sache 604 Mal einzeln untereinander steht. Deshalb liegt der
Schwerpunkt dieses Pakets auf **Bündelung**, nicht auf Zustellung — zustellen
konnten alle vier schon.

## Installation

```bash
composer require peppermint/notifications
php artisan vendor:publish --tag=notifications-config
```

Danach passiert erst einmal **nichts**: `run_migrations` steht auf `false`, und
das Paket liefert kein Vokabular. Beides ist Absicht — ein Produkt mit 17.000
Bestandszeilen darf durch das Einbinden eines Pakets nicht verändert werden.

## Die drei Entscheidungen beim Einbauen

### 1. Welche Tabelle, welche Spalten

Kein Produkt zieht um. Die vorhandene Tabelle wird adoptiert:

```php
// config/notifications.php
'tables' => ['notifications' => 'meldungen'],
'columns' => [
    'notifications' => [
        'user_id' => 'empfaenger_id',
        'title'   => 'ueberschrift',
        'is_read' => 'gelesen',
        // … nur das, was abweicht
    ],
],
```

Die **Zielform** ist die des Peppermint Managers: echte Spalten statt alles in
einem `data`-JSON. Das ist keine Geschmacksfrage — Bündeln, Sortieren und
Indexieren braucht Spalten, und `json_extract()` in einer `GROUP BY`-Klausel ist
auf keinem Treiber indexierbar.

Drei der vier Produkte haben die Laravel-Standardform. Sie bekommen die
fehlenden Spalten **additiv nachgerüstet**, der Altbestand wird aus `data`
befüllt. Gelöscht wird dabei nichts.

### 2. Welche Arten es gibt

Das Paket liefert **keine**. Der Manager unterscheidet 39, AI Brain bis heute
keine einzige. Zu diesen beiden ein gemeinsames Vokabular zu erfinden, hieße für
beide das falsche; „Neue Mail" bedeutet im Manager etwas und im CRM nichts.

```php
'types' => [
    ['key' => 'task.assigned', 'label' => 'Aufgabe zugewiesen',
     'icon' => 'user-plus', 'color' => 'blue'],
],
```

#### Ein vorhandenes Enum anmelden

Ein Produkt behält sein Enum und meldet es an, statt es zu ersetzen:

```php
// AppServiceProvider::boot()
app(TypeRegistry::class)->registerMany(
    array_map(fn (NotificationType $t) => [
        'key'   => $t->value,
        'label' => $t->label(),
        'icon'  => $t->icon(),
        'color' => $t->color(),
    ], NotificationType::cases()),
);
```

Eine Art, die **nicht** angemeldet ist, fällt auf eine Vorgabe zurück statt zu
werfen: Eine Benachrichtigung ohne passendes Symbol ist besser als eine
Ausnahme, die dem Leser die ganze Liste nimmt. Wer Vollständigkeit erzwingen
will, schaltet `strict_types` ein.

### 3. Allein oder über AI Brain

```php
'hub' => 'local',  // oder 'brain'
```

- **`local`** — das Produkt steht für sich. Eigene Tabelle, eigene
  VAPID-Schlüssel, vollständiges Benachrichtigungssystem. Wer nur den Manager
  einsetzt, braucht nichts weiter.
- **`brain`** — das Produkt schreibt **weiterhin zuerst lokal** und meldet die
  Zustellung zusätzlich an AI Brain. Brain pusht und spiegelt die Meldung an die
  anderen Produkte zurück.

Dass auch im `brain`-Betrieb lokal geschrieben wird, ist der wichtigste
Unterschied zu `peppermint/contacts`: Eine Benachrichtigung ist nichts wert,
wenn sie beim Ausfall der Gegenstelle unsichtbar wird. Die Glocke zeigt dann
einen unvollständigen Stand — aber sie zeigt einen. **Fail-open, nicht
fail-closed.**

## Benutzung

### Zustellen

```php
app(Notifier::class)->send(
    to: $empfaenger,
    type: 'task.assigned',
    title: 'Aufgabe zugewiesen',
    message: "Du wurdest \"{$task->title}\" zugewiesen.",
    subject: $task,
    triggeredBy: auth()->user(),
    link: "/tasks/{$task->id}",
);
```

Gibt `null` zurück, wenn der **Einmal-Riegel** gegriffen hat — niemand wird
über die eigene Handlung benachrichtigt. Im Manager steht diese Prüfung heute
28 Mal einzeln da; hier einmal.

Der Dienst **sucht keine Empfänger** („alle Projektmitglieder außer dem
Auslöser" greift in produkteigene Beziehungen) und **pusht nicht selbst**. Er
feuert `NotificationCreated`; wer pushen oder spiegeln will, hängt sich daran.

### Rollen statt wiederholen

```php
app(Notifier::class)->rolling(
    to: $empfaenger,
    type: 'chat.message',
    title: $unterhaltung->name,
    message: fn (int $n) => "{$n} neue Nachrichten",
    subject: $unterhaltung,
    triggeredBy: $absender,
);
```

Gibt es zu diesem Gegenstand schon eine **ungelesene** Meldung dieser Art für
diese Person, wird sie fortgeschrieben statt verdoppelt. Erst wenn jemand
gelesen hat, beginnt ein neuer Zyklus.

Der Zähler steht in `data.rolled_count`, **nicht** im Text. Der Manager liest
ihn heute per regulärem Ausdruck aus der Nachricht zurück
(`/^(\d+) neue Nachrichten$/`) — das hält genau so lange, wie niemand den
Wortlaut ändert, und beim Übersetzen des Produkts gar nicht.

Fortschreiben feuert `NotificationRolled`, **nicht** `NotificationCreated`: Wer
pusht, soll hier normalerweise schweigen. Der Browser hat die Meldung schon
gezeigt, und ein zweiter Ton für dieselbe Sache ist genau die Flut, gegen die
das Rollen gebaut ist.

### Lesen

```php
app(NotificationFeed::class)->for($user);          // ['items' => [...], 'unread_count' => 7]
app(NotificationFeed::class)->unreadCount($user);  // nur die Zahl
```

Gleichartige ungelesene Meldungen am selben Gegenstand bilden **eine** Zeile
mit Zähler. Jede Zeile behält die Kennungen aller Meldungen dahinter (`ids`) —
sonst träfe „gelesen" nur eine davon, und der Zähler im Kopf widerspräche der
Liste darunter.

Die Zahl am Abzeichen zählt **Einzelmeldungen**, nicht Zeilen: Sie soll sagen,
wie viel ungesehen ist, nicht wie viele Kacheln daraus wurden.

### Aktionen

```php
app(NotificationActions::class)->markRead($user, $ids);   // immer eine LISTE
app(NotificationActions::class)->markAllRead($user);
app(NotificationActions::class)->delete($user, $ids);
```

Der Empfänger-Filter **ist** die Berechtigung. Fremde Kennungen laufen ins
Leere, statt den ganzen Aufruf scheitern zu lassen — bei gebündelten Zeilen
kann eine veraltete Kennung dabei sein, ohne dass jemand etwas falsch macht.

### Routen (optional)

```php
Route::middleware(['web', 'auth'])
    ->prefix('notifications')
    ->name('notifications.')
    ->group(base_path('vendor/peppermint/notifications/routes/notifications.php'));
```

Mitgeliefert wegen einer Falle: Steht irgendwo eine Route mit Platzhalter
(`/{notification}`), schluckt die Modellbindung `read-many` und `destroy-many`
als Kennung. Im Manager steht deshalb ein Warnkommentar in `routes/web.php`.
Hier gibt es gar keine Platzhalter-Route mehr — jede Aktion nimmt ohnehin eine
Liste —, und ein Test hält das fest.

## Die Oberfläche

```bash
npm install github:peppermint-digital/notifications
```

Dazu **eine Zeile** in der `app.css` des Produkts, sonst fehlen die Farben:

```css
@source '../../node_modules/@peppermint-digital/notifications/dist';
```

Tailwind scannt `node_modules` nicht von allein. Ohne die Zeile rendert alles,
nur eben farblos — und das sieht nach einem Designfehler aus, nicht nach einer
fehlenden Konfiguration.

### Glocke

```tsx
const feed = useNotifications();          // holt, pollt, handelt

<NotificationBell
    items={feed.items}
    unreadCount={feed.unreadCount}
    onResolve={feed.resolve}
    onDelete={feed.remove}
    onMarkAllRead={feed.markAllRead}
    onOpen={(item) => item.link && router.visit(item.link)}
    footer={<Link href="/notifications">Alle ansehen</Link>}
/>
```

### Liste (Posteingang, eigene Seite)

Dieselbe Komponente, nur ohne Klappe — die Glocke rendert intern genau diese
Liste. Dass es **eine** ist, ist der Punkt: Im Manager gab es sie zweimal, und
die Glocke kannte 15 der 39 Arten.

```tsx
<NotificationList
    items={feed.items}
    onResolve={feed.resolve}
    onDelete={feed.remove}
    onMarkAllRead={feed.markAllRead}
    onDeleteAll={feed.removeAll}
    formatTime={formatRelative}
/>
```

### Was das Produkt beisteuert

Alles, was nur dort Sinn ergibt, kommt als Funktion herein statt als Sonderfall
hineingebaut zu werden:

| Haken | wofür |
|---|---|
| `onOpen` | Navigation — Inertia-`router` statt `window.location` |
| `isDuplicate` | was anderswo schon steht (im Manager: die Aufgaben-Reiter) |
| `renderActions` | zusätzliche Knöpfe (im Manager: Rückruf bei verpasstem Anruf) |
| `renderDetails` | Zusatzzeilen (Aufgabentitel, Projektname) |
| `formatTime` | „vor 2 Stunden" statt einer ISO-Zeichenkette |

Fremde Quellen — im Manager die Wiedervorlagen — mischt das Produkt selbst in
`items` und setzt `source`; das Paket behandelt sie wie jede andere Zeile.

### Abhängigkeiten

`lucide-react` ist eine **Peer**-Abhängigkeit mit weiter Spanne
(`>=0.400.0 <2`). Gemessen am 16.10.2026: AI Brain liegt auf `0.475`,
Manager, CRM und Verwaltung auf `1.x`. Eine engere Angabe machte die
Installation in mindestens einem der vier unmöglich — der Fehler, der beim
Tiptap-Paket schon einmal jede Installation gekostet hat.

**Und die Falle in der Falle:** Der erste Versuch hieß `^0.400.0 || ^1.0.0`
und scheiterte trotzdem. Bei Versionen unter 1.0 fixiert das Dach die
**Minor**-Stelle — `^0.400.0` heißt `>=0.400.0 <0.401.0` und schließt `0.475`
aus. Wer eine Spanne über 0.x-Versionen braucht, schreibt sie aus.

Radix wird **nicht** gezogen. Die Klappe der Glocke ist von Hand gebaut: eine
weitere Peer-Abhängigkeit, die in vier Produkten in vier Fassungen vorliegen
kann, lohnt für ein Aufklappen nicht.

## Tests

```bash
composer install
bin/test-beide-treiber.sh

npm install
npm test
```

SQLite allein genügt nicht: Es nimmt Dinge an, an denen MySQL abbricht, und drei
der vier Produkte laufen in Produktion auf MySQL. Die Wegwerf-Datenbank für den
zweiten Lauf nennt das Skript, wenn sie fehlt.

## Zwei Fallen, beide schon bezahlt

**Eine Modellmethode darf nicht heißen wie ein Feld.** Findet Eloquent zu einem
Attribut, das nicht in `$attributes` steht, eine gleichnamige Methode, ruft es
sie als *Beziehung* auf. Eine Methode `icon()`, die `field('icon')` liest, ruft
damit sich selbst — bis der Speicher voll ist, mit einer Fehlermeldung tief im
Dienst-Container, die nirgends auf das Modell zeigt. Deshalb `iconName()` und
`typeDefinition()`. Ein Test wacht darüber
(`tests/Feature/ModellMethodenNamenTest.php`).

**Umwandlungen gehören auf die echten Spaltennamen.** Eine Umwandlung, die auf
`is_read` registriert ist, läuft in einer Tabelle mit der Spalte `gelesen`
nie — und `=== true` ist dort ab da überall falsch.

## Lizenz

MIT.
