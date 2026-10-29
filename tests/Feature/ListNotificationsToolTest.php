<?php

use Peppermint\Notifications\Delivery\Notifier;
use Peppermint\Notifications\Mcp\ListNotificationsTool;
use Peppermint\Notifications\Tests\Support\User;
use Peppermint\Notifications\Types\TypeRegistry;

/**
 * Die Anbieter-Seite: Ein Produkt gibt seine Meldungen heraus, damit andere
 * sie LESEN statt kopieren (#8186).
 */
beforeEach(function (): void {
    app(TypeRegistry::class)->registerMany([
        ['key' => 'task.assigned', 'label' => 'Aufgabe zugewiesen', 'icon' => 'user-plus', 'color' => 'blue'],
    ]);

    $this->ich = User::create(['name' => 'Ich', 'email' => 'ich@beispiel.test']);
    $this->andere = User::create(['name' => 'Andere', 'email' => 'andere@beispiel.test']);
});

it('liefert nichts ohne handelnde Person', function (): void {
    // Eine Benachrichtigung gehoert immer genau einem Menschen. Ein Aufruf
    // ohne Person koennte nur „alle" meinen — ueber Systemgrenzen hinweg
    // waere das ein Datenleck.
    app(Notifier::class)->send(to: $this->ich, type: 'task.assigned', title: 'Meine');

    $antwort = werkzeugAufrufen();

    expect($antwort['items'])->toBe([])
        ->and($antwort['unread_count'])->toBe(0);
});

it('liefert nur die Meldungen der handelnden Person', function (): void {
    app(Notifier::class)->send(to: $this->ich, type: 'task.assigned', title: 'Meine');
    app(Notifier::class)->send(to: $this->andere, type: 'task.assigned', title: 'Fremde');

    $this->actingAs($this->ich);

    $antwort = werkzeugAufrufen();

    expect($antwort['items'])->toHaveCount(1)
        ->and($antwort['items'][0]['title'])->toBe('Meine');
});

it('legt den Namen des eigenen Systems bei', function (): void {
    // Sonst muesste der Aufrufer ihn aus der Antwort raten.
    config()->set('notifications.labels.local', 'Verwaltung');
    $this->actingAs($this->ich);

    expect(werkzeugAufrufen()['source'])->toBe('Verwaltung');
});

it('haelt sich an die Obergrenze', function (): void {
    foreach (range(1, 5) as $i) {
        app(Notifier::class)->send(to: $this->ich, type: 'task.assigned', title: "M{$i}");
    }

    $this->actingAs($this->ich);

    expect(werkzeugAufrufen(['limit' => 2])['items'])->toHaveCount(2);
});

it('deckelt eine unsinnig hohe Grenze', function (): void {
    $this->actingAs($this->ich);

    // Nicht aus Misstrauen gegen den Aufrufer, sondern weil die Antwort
    // ueber die Leitung geht: Eine Grenze von einer Million waere ein
    // Zeitueberschreitungs-Fehler, kein Ergebnis.
    expect(werkzeugAufrufen(['limit' => 999999]))->toHaveKey('items');
});

/**
 * Das Werkzeug aufrufen und die Nutzlast herausschaelen.
 */
function werkzeugAufrufen(array $argumente = []): array
{
    $antwort = app(ListNotificationsTool::class)->handle(
        new \Laravel\Mcp\Request($argumente),
        app(\Peppermint\Notifications\Feed\NotificationFeed::class),
    );

    $inhalt = $antwort->content();

    // `Response::json()` legt die Nutzlast als Text ab. Der Weg dorthin hat
    // sich zwischen den SDK-Fassungen schon geaendert — deshalb wird der
    // Inhalt zu Text gemacht und gelesen, statt eine Methodenkette
    // festzuschreiben, die beim naechsten Update bricht.
    $daten = json_decode((string) $inhalt, true);

    return is_array($daten) ? $daten : [];
}

/*
|--------------------------------------------------------------------------
| Der Verweis traegt die eigene Adresse mit (AI Brain #1151)
|--------------------------------------------------------------------------
|
| Diese Antwort verlaesst das System. Ein `/tasks/5` ist zu Hause richtig
| und nur dort — drueben loest der Browser ihn gegen den falschen Host auf.
|
| Beim SPIEGELN war das immer richtig (MirrorPayload). Auf diesem Weg
| fehlte es, und genau dieser Weg wird seit dem Umbau auf „fragen statt
| kopieren" benutzt.
|
*/

it('gibt den Verweis absolut heraus, weil diese Liste das System verlaesst', function (): void {
    config()->set('app.url', 'https://brain.proxy.peppermint-digital.com');

    app(Notifier::class)->send(
        to: $this->ich,
        type: 'task.assigned',
        title: 'Bug behoben',
        link: '/bugs/1122',
    );

    $this->actingAs($this->ich);

    // Gemessen am 29.10.2026: Relativ herausgegeben antwortete genau dieser
    // Pfad auf vw, crm und pm mit 404.
    expect(werkzeugAufrufen()['items'][0]['link'])
        ->toBe('https://brain.proxy.peppermint-digital.com/bugs/1122');
});

it('laesst einen schon absoluten Verweis in Ruhe', function (): void {
    // Der Manager baut seine Verweise seit immer selbst absolut. Ein
    // zweites Praefix davor machte aus einem richtigen einen kaputten — und
    // zwar nur in den Zeilen, die vorher funktionierten.
    config()->set('app.url', 'https://brain.proxy.peppermint-digital.com');

    app(Notifier::class)->send(
        to: $this->ich,
        type: 'task.assigned',
        title: 'Aufgabe im Manager',
        link: 'https://pm.peppermint-digital.com/tasks/42',
    );

    $this->actingAs($this->ich);

    expect(werkzeugAufrufen()['items'][0]['link'])
        ->toBe('https://pm.peppermint-digital.com/tasks/42');
});

it('laesst eine Meldung ohne Verweis ohne Verweis', function (): void {
    // Kein Ziel zu haben ist eine Aussage, kein fehlender Wert. Ein
    // erfundenes Ziel waere schlechter als keines.
    config()->set('app.url', 'https://brain.proxy.peppermint-digital.com');

    app(Notifier::class)->send(to: $this->ich, type: 'task.assigned', title: 'Nur ein Hinweis');

    $this->actingAs($this->ich);

    expect(werkzeugAufrufen()['items'][0]['link'])->toBeNull();
});
