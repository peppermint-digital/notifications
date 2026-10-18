<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Das Nutzer-Modell
    |--------------------------------------------------------------------------
    |
    | Das Paket verwaltet keine Menschen, es haelt nur einen Verweis.
    |
    */
    'user_model' => env('NOTIFICATIONS_USER_MODEL', 'App\\Models\\User'),

    /*
    |--------------------------------------------------------------------------
    | Wer verteilt — der Betriebsmodus
    |--------------------------------------------------------------------------
    |
    | `local` — das Produkt steht fuer sich. Es schreibt seine Meldungen in
    |           die eigene Tabelle und pusht, wenn es eigene VAPID-Schluessel
    |           hat. Wer nur den Manager einsetzt, bekommt damit ein
    |           vollstaendiges Benachrichtigungssystem.
    |
    | `brain`  — das Produkt schreibt weiterhin ZUERST lokal und meldet die
    |           Zustellung zusaetzlich an AI Brain. Brain pusht und spiegelt
    |           die Meldung an die anderen Produkte zurueck.
    |
    | Dass auch im `brain`-Betrieb lokal geschrieben wird, ist Absicht und der
    | wichtigste Unterschied zu peppermint/contacts: Eine Benachrichtigung ist
    | nichts wert, wenn sie beim Ausfall der Gegenstelle unsichtbar wird. Die
    | Glocke zeigt dann einen unvollstaendigen Stand — aber sie zeigt einen.
    | Fail-open, nicht fail-closed.
    |
    */
    'hub' => env('NOTIFICATIONS_HUB', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Tabellen
    |--------------------------------------------------------------------------
    |
    | Paketname => tatsaechlicher Tabellenname. Kein Produkt zieht um: alle
    | vier haben Bestandsdaten, AI Brain allein ueber 17.000 Zeilen.
    |
    */
    'tables' => [
        'notifications' => 'notifications',
        'push_subscriptions' => 'push_subscriptions',
        'preferences' => 'notification_preferences',
        'digests' => 'notification_digests',
    ],

    /*
    |--------------------------------------------------------------------------
    | Der Schluesseltyp
    |--------------------------------------------------------------------------
    |
    | `int` oder `string`.
    |
    | Drei der vier Produkte stehen auf der Laravel-Standardtabelle mit einem
    | UUID-Schluessel, nur der Manager zaehlt hoch. Ohne die richtige Angabe
    | castet Eloquent einen UUID zu einer Zahl — aus `9b1d…` wird `9`, und zwei
    | Meldungen tragen ploetzlich dieselbe Kennung.
    |
    */
    'key_type' => env('NOTIFICATIONS_KEY_TYPE', 'int'),

    /*
    |--------------------------------------------------------------------------
    | Spalten
    |--------------------------------------------------------------------------
    |
    | Paket-Feldname => tatsaechlicher Spaltenname. Damit ein gewachsenes
    | Produkt seine jahrealten Spaltennamen behaelt, waehrend das Paket seine
    | eigenen spricht.
    |
    | Die Zielform ist die des Peppermint Managers — echte Spalten statt alles
    | in einem `data`-JSON. Das ist keine Geschmacksfrage: Buendeln, Sortieren
    | und Indexieren braucht Spalten. Die drei Produkte mit der
    | Laravel-Standardform bekommen sie additiv nachgeruestet, der Altbestand
    | wird aus `data` befuellt. Geloescht wird dabei nichts.
    |
    | ACHTUNG bei der Standardform: Dort ist `notifiable_*` der EMPFAENGER,
    | nicht der Gegenstand — und `type` die Notification-Klasse, nicht die Art.
    | Beides bekommt deshalb eigene Spalten und wird hier umgebogen:
    |
    |     'type'            => 'kind',
    |     'notifiable_type' => 'subject_type',
    |     'notifiable_id'   => 'subject_id',
    |
    */
    'columns' => [
        'notifications' => [],
        'push_subscriptions' => [],
        'preferences' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Das Typ-Vokabular
    |--------------------------------------------------------------------------
    |
    | Jedes Produkt meldet seine Arten an; das Paket liefert KEINE.
    |
    | Der Grund steht in den Zahlen: Der Manager unterscheidet 39 Arten, AI
    | Brain bis heute keine einzige — eine Klasse fuer alle 17.356 Zeilen. Zu
    | diesen beiden ein gemeinsames Vokabular zu erfinden, hiesse fuer beide
    | das falsche. "Neue Mail" bedeutet im Manager etwas und im CRM nichts.
    |
    | Angemeldet wird als Liste von Feldern, nicht als Klasse je Art: Eine Art
    | hat kein Verhalten, nur eine Beschreibung. 39 Klassen ohne Methoden
    | waeren Aufwand ohne Gegenwert — anders als bei den Aufgaben-Status, wo
    | an jedem Status eine Entscheidung haengt (was heisst "erledigt").
    |
    |   'types' => [
    |       ['key' => 'task.assigned', 'label' => 'Aufgabe zugewiesen',
    |        'icon' => 'user-plus', 'color' => 'blue'],
    |   ],
    |
    | Ein Produkt mit einem bestehenden Enum bildet es hier ab, statt es zu
    | ersetzen — siehe README, Abschnitt "Ein vorhandenes Enum anmelden".
    |
    */
    'types' => [],

    /*
    | Was gilt fuer eine Art, die nirgends angemeldet ist?
    |
    | Bewusst ein Rueckfall und kein Fehler: Eine Benachrichtigung ohne
    | passendes Symbol ist besser als keine Benachrichtigung. Im Manager ist
    | genau dieser Fall schon einmal eingetreten — die Glocke kannte 15 der 39
    | Arten, der Rest sah aus wie eine Erinnerung. Sichtbar falsch ist besser
    | als unsichtbar; wer es merken will, schaltet `strict_types` ein.
    */
    'unknown_type' => [
        'label' => 'Benachrichtigung',
        'icon' => 'bell',
        'color' => 'gray',
    ],

    /*
    | Mit `true` wirft eine unbekannte Art statt zurueckzufallen. Fuer Tests
    | und fuer Produkte, die ihr Vokabular vollstaendig halten wollen — der
    | Manager tut das heute ueber einen eigenen Test (NotificationCoverageTest).
    */
    'strict_types' => env('NOTIFICATIONS_STRICT_TYPES', false),

    /*
    |--------------------------------------------------------------------------
    | Anzeige
    |--------------------------------------------------------------------------
    */
    'feed' => [
        /*
        | Wie weit die gelesene Vergangenheit mitgeliefert wird. Ungelesenes
        | kommt immer mit, egal wie alt. Gelesenes ist Verlauf und nach einer
        | Woche Ballast.
        */
        'recent_days' => 7,

        /* Deckel gegen das Postfach, in dem seit Monaten niemand aufgeraeumt hat. */
        'max_items' => 50,

        /*
        | Gleichartiges zu einer Zeile zusammenfassen.
        |
        | Gebuendelt wird in der ANZEIGE, nicht in der Datenbank: Jede Zeile
        | behaelt die Kennungen aller Meldungen dahinter, damit "gelesen" und
        | "loeschen" weiterhin jeden einzelnen Datensatz treffen und nichts
        | still verschwindet.
        |
        | Nur Ungelesenes wird gebuendelt. Ein Verlauf, der Eintraege
        | zusammenzieht, ist keiner mehr.
        */
        'bundle' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Alt-Spalten der adoptierten Tabelle
    |--------------------------------------------------------------------------
    |
    | Spalten, die die vorhandene Tabelle als NOT NULL fuehrt und die das
    | Paket nicht kennt.
    |
    | Die Laravel-Standardtabelle — drei der vier Produkte stehen darauf —
    | verlangt `type`, `notifiable_type`, `notifiable_id` und `data`. Fuer das
    | Paket sind das Altlasten: `type` ist dort die Notification-Klasse,
    | `notifiable_*` der Empfaenger. Solange nur Laravel schreibt, fuellt
    | Laravel sie. Sobald das Paket SELBST anlegt (beim Spiegeln, oder wenn
    | ein Produkt mit Standardform den Notifier benutzt), scheitert das INSERT.
    |
    |     'legacy_columns' => [
    |         'type' => 'App\Notifications\AppNotification',
    |         'notifiable_type' => 'user',
    |         'notifiable_id' => '@user_id',
    |         'data' => '{}',
    |     ],
    |
    | Ein Wert ist entweder fest oder eine Referenz auf ein Paketfeld
    | (`@feldname`). Gesetzt wird nur, was noch leer ist.
    |
    | KEINE Closure hier: `php artisan config:cache` schreibt die
    | Konfiguration per `var_export` in eine Datei, und eine Closure
    | ueberlebt das nicht. Der Befehl bricht ab — und in einem Produkt mit
    | Zero-Downtime-Deploy bedeutet das ein Release, das ausgecheckt und nie
    | umgehaengt wird.
    |
    */
    'legacy_columns' => [],

    /*
    |--------------------------------------------------------------------------
    | Spiegelung ueber AI Brain
    |--------------------------------------------------------------------------
    |
    | Gilt nur bei `hub => 'brain'`. Dann meldet dieses Produkt jede neue
    | Meldung zusaetzlich an den Hub, und der verteilt sie an die Produkte,
    | die diese Art abonniert haben.
    |
    */
    'mirror' => [
        /*
        | WELCHE Arten nach draussen gehen. Die Vorgabe ist eine LEERE Liste,
        | also keine.
        |
        | Das ist Absicht und nicht Vorsicht: „Neue E-Mail in Konto 3" hat im
        | CRM nichts verloren, und eine Infrastruktur-Meldung aus dem Brain
        | erst recht nicht — davon entstehen dort 243 am Tag. Gespiegelt wird,
        | was jemand ausdruecklich benennt.
        |
        |     'types' => ['task.assigned', 'mention'],
        |
        | `['*']` spiegelt alles. Erlaubt, selten richtig.
        */
        'types' => [],

        /*
        | Der Name dieses Produkts in der gespiegelten Meldung. Er steht
        | drueben als Herkunft und sollte der Produkt-Slug sein, nicht der
        | Anzeigename.
        */
        'origin' => env('NOTIFICATIONS_ORIGIN', env('AI_BRAIN_PRODUCT_SLUG')),

        /*
        | Die Spalte, in der die E-Mail-Adresse steht.
        |
        | Sie ist der einzige produktuebergreifende Schluessel: Nutzer-Nummern
        | gelten je Produkt, und dieselbe `5` ist in vier Datenbanken vier
        | Menschen.
        */
        'email_column' => 'email',
    ],

    /*
    |--------------------------------------------------------------------------
    | Migrationen
    |--------------------------------------------------------------------------
    |
    | Der Normalfall ist `false`: Alle vier Produkte haben ihre Tabelle
    | laengst. `true` ist fuer ein Produkt, das bei null anfaengt.
    |
    */
    'run_migrations' => env('NOTIFICATIONS_RUN_MIGRATIONS', false),

    /*
    |--------------------------------------------------------------------------
    | Push
    |--------------------------------------------------------------------------
    |
    | Ein Web-Push-Abo haengt am VAPID-PUBLIC-KEY, mit dem der Browser
    | abonniert hat — nicht an der Domain. Holen alle Produkte denselben
    | Schluessel, kann nur die Stelle signieren, die den privaten Teil haelt.
    |
    | Im `brain`-Betrieb kommt er von `hub_url`; `public_key` ist dann nur
    | noch der Rueckfall fuer den Fall, dass der Hub nicht antwortet. Im
    | Einzelbetrieb ist `public_key` die Quelle.
    |
    | ACHTUNG beim Umstellen: Bestehende Abos gelten NICHT weiter. Sie sind
    | mit dem alten Schluessel entstanden, und der Push-Dienst lehnt sie mit
    | dem neuen ab. Jeder Mensch abonniert einmal neu.
    |
    */
    'push' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),

        'hub_url' => env('NOTIFICATIONS_PUSH_HUB_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Wer bekommt was, auf welchem Weg
    |--------------------------------------------------------------------------
    |
    | Hier steht die VORGABE des Produkts — was sinnvoll ist. Was ein Mensch
    | WILL, steht in `notification_preferences`, eine Zeile je Mensch und Art.
    |
    | Wer nichts eingestellt hat, hat keine Zeile und folgt dieser Vorgabe
    | weiter — auch wenn sie sich spaeter aendert. Das ist der Grund, warum
    | beim Einrichten nicht fuer jeden Menschen Zeilen angelegt werden.
    |
    | Stufen: `instant`, `digest`, `off`.
    |
    | Fuer E-Mail ist `digest` die Vorgabe, und das ist kein Zufall: Push und
    | Glocke kosten eine Zeile, eine E-Mail kostet eine E-Mail. Bei 243
    | Meldungen am Tag allein aus AI Brain ist „bei jeder Art eine Mail" ein
    | Weg, sich selbst zuzuspammen — und wer zugespammt wird, schaltet nicht
    | die Mails ab, sondern alles.
    |
    */
    'preferences' => [
        'defaults' => [
            'push' => 'instant',
            'inapp' => 'instant',

            // `off`, und das ist eine bewusste Abkehr von der urspruenglichen
            // Vorgabe `digest` (#8165).
            //
            // Solange der E-Mail-Weg nichts tat, war `digest` harmlos. Mit dem
            // Einschalten haette er bedeutet: Jeder Kollege bekommt ab sofort
            // taeglich Post, ohne je etwas eingestellt zu haben. Der Kanal,
            // der am meisten kostet, gehoert nicht in die Voreinstellung — er
            // gehoert zum Mitmachen, nicht zum Abbestellen.
            'email' => 'off',
        ],

        /*
        | Abweichungen je Art, zum Beispiel:
        |
        |     'per_type' => [
        |         'invoice.approval' => ['email' => 'instant'],
        |         'chat.message' => ['email' => 'off'],
        |     ],
        */
        'per_type' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Wie die Systeme heissen
    |--------------------------------------------------------------------------
    |
    | Ueber die Leitung kommt ein Produkt-Kuerzel (`peppermint-manager`). Was
    | es fuer einen Leser heisst, steht hier — und nur hier, damit der
    | Posteingang in jedem System dieselben Woerter benutzt.
    |
    | `local` ist der Name DIESES Systems. Er steht an den Zeilen, die von
    | hier stammen; die tragen kein Kuerzel, weil es fuer sie keines gibt.
    | Ohne Angabe wird `app.name` genommen.
    |
    */
    'labels' => [
        'local' => env('NOTIFICATIONS_LOCAL_LABEL'),

        /** @var array<string, string> Kuerzel => Name */
        'sources' => [],
    ],

];
