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
    ],

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
    | wird aus `data` befuellt. Gelesen wird dabei nichts geloescht.
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
    | Migrationen
    |--------------------------------------------------------------------------
    |
    | Der Normalfall ist `false`: Alle vier Produkte haben ihre Tabelle
    | laengst. `true` ist fuer ein Produkt, das bei null anfaengt.
    |
    */
    'run_migrations' => env('NOTIFICATIONS_RUN_MIGRATIONS', false),

];
