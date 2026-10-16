# peppermint/notifications

Benachrichtigungs-Kern für Laravel. Eine Meldung je Person, ein Vokabular je
Produkt, Zustellung über Glocke und Web-Push — zentral über AI Brain oder für
sich allein.

Dazu `@peppermint-digital/notifications` aus demselben Repository: die
Oberfläche, damit die Liste in jedem Produkt gleich aussieht.

> **Stand:** N1 (Gerüst, Adoption, Typ-Register). Die Zustellmechanik (N2), die
> Oberfläche (N3) und die Spiegelung über AI Brain (N7) folgen. Die Schritte
> stehen als Aufgaben im AI-Brain-Projekt `notifications`.

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

## Tests

```bash
composer install
bin/test-beide-treiber.sh
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
