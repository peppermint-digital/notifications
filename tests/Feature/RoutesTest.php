<?php

use Illuminate\Support\Facades\Route;
use Peppermint\Notifications\Delivery\Notifier;
use Peppermint\Notifications\Models\Notification;
use Peppermint\Notifications\Tests\Support\User;
use Peppermint\Notifications\Tests\Support\Widget;

beforeEach(function (): void {
    Route::middleware('web')
        ->prefix('notifications')
        ->name('notifications.')
        ->group(__DIR__.'/../../routes/notifications.php');

    $this->notifier = app(Notifier::class);
    $this->ich = User::create(['name' => 'Ich']);
    $this->andere = User::create(['name' => 'Jemand anders']);
});

/**
 * Die mitgelieferten Routen sind optional — aber wenn sie benutzt werden,
 * muessen sie das tun, was draufsteht.
 */
it('liefert die eigene Liste', function (): void {
    $this->notifier->send(to: $this->ich, type: 'mention', title: 'Meine');
    $this->notifier->send(to: $this->andere, type: 'mention', title: 'Fremde');

    $antwort = $this->actingAs($this->ich)->getJson('/notifications');

    $antwort->assertOk()
        ->assertJsonPath('unread_count', 1)
        ->assertJsonPath('items.0.title', 'Meine')
        ->assertJsonCount(1, 'items');
});

it('liefert die Zahl allein', function (): void {
    $this->notifier->send(to: $this->ich, type: 'mention', title: 'Meine');

    $this->actingAs($this->ich)->getJson('/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('unread_count', 1);
});

it('markiert eine Liste von Kennungen als gelesen', function (): void {
    $widget = Widget::create(['title' => 'Server A']);
    $ids = collect(range(1, 3))->map(fn (): int|string => $this->notifier->send(
        to: $this->ich, type: 'infra.unreachable', title: 'Nicht erreichbar', subject: $widget,
    )->getKey())->all();

    $this->actingAs($this->ich)->postJson('/notifications/read', ['ids' => $ids])
        ->assertOk()
        ->assertJsonPath('affected', 3);

    expect(Notification::query()->forUser($this->ich->id)->unread()->count())->toBe(0);
});

it('verlangt eine Liste und keine einzelne Kennung', function (): void {
    // Jede Aktion nimmt MEHRERE — hinter einer gebuendelten Zeile stecken
    // mehrere Datensaetze.
    $this->actingAs($this->ich)->postJson('/notifications/read', ['ids' => 5])
        ->assertStatus(422);
});

it('deckelt die Zahl der Kennungen schon an der Tuer', function (): void {
    $zuviele = range(1, 500);

    $this->actingAs($this->ich)->postJson('/notifications/read', ['ids' => $zuviele])
        ->assertStatus(422);
});

it('markiert alles als gelesen', function (): void {
    $this->notifier->send(to: $this->ich, type: 'mention', title: 'A');
    $this->notifier->send(to: $this->ich, type: 'mention', title: 'B');

    $this->actingAs($this->ich)->postJson('/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('affected', 2);
});

it('loescht eine Liste und alles', function (): void {
    $eine = $this->notifier->send(to: $this->ich, type: 'mention', title: 'A')->getKey();
    $this->notifier->send(to: $this->ich, type: 'mention', title: 'B');

    $this->actingAs($this->ich)->deleteJson('/notifications', ['ids' => [$eine]])
        ->assertOk()
        ->assertJsonPath('affected', 1);

    $this->actingAs($this->ich)->deleteJson('/notifications/all')
        ->assertOk()
        ->assertJsonPath('affected', 1);

    expect(Notification::count())->toBe(0);
});

it('schluckt "all" nicht als Kennung', function (): void {
    // Die Falle, deretwegen die Routen mitgeliefert werden: Steht irgendwo
    // eine Route mit Platzhalter (/{notification}), nimmt die Modellbindung
    // das Wort „all" als Kennung entgegen — der Aufruf landet auf der
    // falschen Methode und scheitert mit einem 404 auf eine Meldung namens
    // „all". Im Manager steht genau deshalb ein Warnkommentar in den Routen.
    //
    // Dieses Paket hat gar keine Platzhalter-Route. Der Test haelt das fest:
    // Wer eine ergaenzt, sieht hier rot.
    $this->notifier->send(to: $this->ich, type: 'mention', title: 'A');

    $this->actingAs($this->ich)->deleteJson('/notifications/all')
        ->assertOk()
        ->assertJsonPath('affected', 1);

    $platzhalter = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r): bool => str_starts_with($r->uri(), 'notifications'))
        ->filter(fn ($r): bool => str_contains($r->uri(), '{'))
        ->map->uri()
        ->values()
        ->all();

    expect($platzhalter)->toBe(
        [],
        'Eine Platzhalter-Route unter notifications/ holt die Modellbindung zurueck '
        .'und damit die Falle: '.implode(', ', $platzhalter)
    );
});
