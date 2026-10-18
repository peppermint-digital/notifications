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
