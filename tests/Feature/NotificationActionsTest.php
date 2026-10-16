<?php

use Peppermint\Notifications\Delivery\Notifier;
use Peppermint\Notifications\Feed\NotificationActions;
use Peppermint\Notifications\Models\Notification;
use Peppermint\Notifications\Tests\Support\User;
use Peppermint\Notifications\Tests\Support\Widget;

beforeEach(function (): void {
    $this->notifier = app(Notifier::class);
    $this->aktionen = app(NotificationActions::class);
    $this->ich = User::create(['name' => 'Ich']);
    $this->andere = User::create(['name' => 'Jemand anders']);
});

function meine(int $anzahl = 1, ?Widget $an = null): array
{
    return collect(range(1, $anzahl))->map(fn (): int|string => test()->notifier->send(
        to: test()->ich, type: 'infra.unreachable', title: 'Server nicht erreichbar', subject: $an,
    )->getKey())->all();
}

it('markiert mehrere Meldungen auf einmal als gelesen', function (): void {
    // Der Normalfall nach dem Buendeln: Hinter einer Zeile stecken mehrere
    // Datensaetze, und ein Klick muss sie alle treffen.
    $ids = meine(4, Widget::create(['title' => 'Server A']));

    expect($this->aktionen->markRead($this->ich, $ids))->toBe(4)
        ->and(Notification::query()->forUser($this->ich->id)->unread()->count())->toBe(0);
});

it('fasst fremde Meldungen nicht an, auch wenn ihre Kennung mitgeschickt wird', function (): void {
    $meine = meine(1);
    $fremde = $this->notifier->send(to: $this->andere, type: 'mention', title: 'Fremd');

    $betroffen = $this->aktionen->markRead($this->ich, [...$meine, $fremde->getKey()]);

    expect($betroffen)->toBe(1)
        ->and($fremde->fresh()->isRead())->toBeFalse();
});

it('laeuft bei einer fremden Kennung ins Leere statt den ganzen Aufruf scheitern zu lassen', function (): void {
    // Bei gebuendelten Zeilen kann eine veraltete Kennung dabei sein, ohne
    // dass jemand etwas falsch macht. Ein 403 fuer den ganzen Aufruf liesse
    // dann auch die gueltigen stehen.
    $meine = meine(2);

    expect($this->aktionen->markRead($this->ich, [...$meine, 999999]))->toBe(2);
});

it('setzt den Lesezeitpunkt beim zweiten Klick nicht neu', function (): void {
    // Sonst sagt der Verlauf, die Meldung sei eben erst gelesen worden.
    $ids = meine(1);
    $this->aktionen->markRead($this->ich, $ids);

    $zeitpunkt = Notification::query()->whereKey($ids[0])->first()->field('read_at');

    expect($this->aktionen->markRead($this->ich, $ids))->toBe(0)
        ->and(Notification::query()->whereKey($ids[0])->first()->field('read_at')->equalTo($zeitpunkt))->toBeTrue();
});

it('markiert alles als gelesen — aber nur das Eigene', function (): void {
    meine(3);
    $fremde = $this->notifier->send(to: $this->andere, type: 'mention', title: 'Fremd');

    expect($this->aktionen->markAllRead($this->ich))->toBe(3)
        ->and($fremde->fresh()->isRead())->toBeFalse();
});

it('macht gelesen wieder ungelesen', function (): void {
    $ids = meine(2);
    $this->aktionen->markRead($this->ich, $ids);

    expect($this->aktionen->markUnread($this->ich, $ids))->toBe(2)
        ->and(Notification::query()->forUser($this->ich->id)->unread()->count())->toBe(2)
        ->and(Notification::query()->whereKey($ids[0])->first()->field('read_at'))->toBeNull();
});

it('loescht mehrere auf einmal — und nur die eigenen', function (): void {
    $meine = meine(3);
    $fremde = $this->notifier->send(to: $this->andere, type: 'mention', title: 'Fremd');

    expect($this->aktionen->delete($this->ich, [...$meine, $fremde->getKey()]))->toBe(3)
        ->and(Notification::count())->toBe(1)
        ->and($fremde->fresh())->not->toBeNull();
});

it('loescht alles Eigene', function (): void {
    meine(4);
    $this->notifier->send(to: $this->andere, type: 'mention', title: 'Fremd');

    expect($this->aktionen->deleteAll($this->ich))->toBe(4)
        ->and(Notification::count())->toBe(1);
});

it('tut bei einer leeren Liste nichts', function (): void {
    meine(2);

    expect($this->aktionen->markRead($this->ich, []))->toBe(0)
        ->and($this->aktionen->delete($this->ich, []))->toBe(0)
        ->and(Notification::count())->toBe(2);
});

it('deckelt die Zahl der Kennungen je Aufruf', function (): void {
    // Damit eine Liste aus dem Browser nicht zu einer Abfrage mit
    // zehntausend Werten wird.
    $ids = meine(5);
    $erfundene = range(100000, 100000 + NotificationActions::MAX_IDS + 50);

    // Die eigenen stehen HINTER dem Deckel und werden damit nicht erfasst —
    // das ist die Folge des Deckels und soll sichtbar sein, nicht still.
    $betroffen = $this->aktionen->markRead($this->ich, [...$erfundene, ...$ids]);

    expect($betroffen)->toBe(0)
        ->and(count($erfundene))->toBeGreaterThan(NotificationActions::MAX_IDS);
});

it('arbeitet auch mit einer blossen Nutzer-Kennung statt eines Modells', function (): void {
    $ids = meine(2);

    expect($this->aktionen->markRead($this->ich->id, $ids))->toBe(2);
});
