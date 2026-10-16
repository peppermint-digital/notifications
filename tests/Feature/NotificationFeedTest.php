<?php

use Peppermint\Notifications\Delivery\Notifier;
use Peppermint\Notifications\Feed\NotificationFeed;
use Peppermint\Notifications\Models\Notification;
use Peppermint\Notifications\Tests\Support\User;
use Peppermint\Notifications\Tests\Support\Widget;
use Peppermint\Notifications\Types\NotificationType;
use Peppermint\Notifications\Types\TypeRegistry;

beforeEach(function (): void {
    $this->notifier = app(Notifier::class);
    $this->feed = app(NotificationFeed::class);
    $this->ich = User::create(['name' => 'Ich']);
    $this->andere = User::create(['name' => 'Jemand anders']);
});

it('liefert nur die eigenen Meldungen', function (): void {
    $this->notifier->send(to: $this->ich, type: 'mention', title: 'Meine');
    $this->notifier->send(to: $this->andere, type: 'mention', title: 'Fremde');

    $liste = $this->feed->for($this->ich);

    expect($liste['items'])->toHaveCount(1)
        ->and($liste['items'][0]['title'])->toBe('Meine');
});

it('fasst gleichartige ungelesene Meldungen am selben Gegenstand zu einer Zeile zusammen', function (): void {
    // Der Fall, der das Paket ausgeloest hat: Im AI Brain steht „Server nicht
    // erreichbar: graceful-athens" 604 Mal untereinander.
    $widget = Widget::create(['title' => 'graceful-athens']);

    foreach (range(1, 5) as $i) {
        $this->notifier->send(
            to: $this->ich, type: 'infra.unreachable',
            title: 'Server nicht erreichbar', subject: $widget,
        );
    }

    $liste = $this->feed->for($this->ich);

    expect($liste['items'])->toHaveCount(1)
        ->and($liste['items'][0]['bundle_count'])->toBe(5)
        ->and($liste['items'][0]['ids'])->toHaveCount(5);
});

it('zaehlt am Abzeichen die Einzelmeldungen, nicht die Zeilen', function (): void {
    // Sonst sagt die Glocke „1", waehrend fuenf Dinge ungesehen sind.
    $widget = Widget::create(['title' => 'graceful-athens']);

    foreach (range(1, 5) as $i) {
        $this->notifier->send(
            to: $this->ich, type: 'infra.unreachable',
            title: 'Server nicht erreichbar', subject: $widget,
        );
    }

    expect($this->feed->for($this->ich)['unread_count'])->toBe(5);
});

it('buendelt NICHT ueber verschiedene Gegenstaende hinweg', function (): void {
    $a = Widget::create(['title' => 'Server A']);
    $b = Widget::create(['title' => 'Server B']);

    foreach ([$a, $b] as $widget) {
        $this->notifier->send(
            to: $this->ich, type: 'infra.unreachable',
            title: 'Server nicht erreichbar', subject: $widget,
        );
    }

    expect($this->feed->for($this->ich)['items'])->toHaveCount(2);
});

it('buendelt ohne Gegenstand nach dem Titel', function (): void {
    // „Aufgabe geloescht" hat keinen Gegenstand mehr. Ohne Titel-Gruppierung
    // fielen drei verschiedene geloeschte Aufgaben in eine Zeile, die nur eine
    // davon nennt.
    $this->notifier->send(to: $this->ich, type: 'task.deleted', title: 'Aufgabe "A" geloescht');
    $this->notifier->send(to: $this->ich, type: 'task.deleted', title: 'Aufgabe "A" geloescht');
    $this->notifier->send(to: $this->ich, type: 'task.deleted', title: 'Aufgabe "B" geloescht');

    $liste = $this->feed->for($this->ich);

    expect($liste['items'])->toHaveCount(2)
        ->and(collect($liste['items'])->pluck('bundle_count')->sort()->values()->all())->toBe([1, 2]);
});

it('buendelt Gelesenes nicht — ein Verlauf, der zusammenzieht, ist keiner', function (): void {
    $widget = Widget::create(['title' => 'Server A']);

    foreach (range(1, 3) as $i) {
        $this->notifier->send(
            to: $this->ich, type: 'infra.unreachable',
            title: 'Server nicht erreichbar', subject: $widget,
        )->markAsRead();
    }

    expect($this->feed->for($this->ich)['items'])->toHaveCount(3);
});

it('behaelt hinter einer gebuendelten Zeile jede einzelne Kennung', function (): void {
    // Sonst traefe „gelesen" nur eine der Meldungen, und der Zaehler im Kopf
    // widerspraeche der Liste darunter.
    $widget = Widget::create(['title' => 'Server A']);

    $ids = collect(range(1, 4))->map(fn (): int|string => $this->notifier->send(
        to: $this->ich, type: 'infra.unreachable',
        title: 'Server nicht erreichbar', subject: $widget,
    )->getKey())->all();

    $zeile = $this->feed->for($this->ich)['items'][0];

    expect($zeile['ids'])->toEqualCanonicalizing($ids);
});

it('traegt in der gebuendelten Zeile den Text der JUENGSTEN Meldung', function (): void {
    $widget = Widget::create(['title' => 'Postfach']);

    $this->notifier->send(
        to: $this->ich, type: 'email.new', title: 'Neue E-Mail',
        message: 'Von gestern', subject: $widget,
    );
    $this->notifier->send(
        to: $this->ich, type: 'email.new', title: 'Neue E-Mail',
        message: 'Gerade eben', subject: $widget,
    );

    expect($this->feed->for($this->ich)['items'][0]['message'])->toBe('Gerade eben');
});

it('stellt Ungelesenes vor den Verlauf', function (): void {
    $alt = $this->notifier->send(to: $this->ich, type: 'mention', title: 'Schon gelesen');
    $alt->markAsRead();

    $this->notifier->send(to: $this->ich, type: 'task.assigned', title: 'Noch ungelesen');

    $titel = collect($this->feed->for($this->ich)['items'])->pluck('title')->all();

    expect($titel)->toBe(['Noch ungelesen', 'Schon gelesen']);
});

it('zeigt Ungelesenes unabhaengig vom Alter, Gelesenes nur aus dem Zeitfenster', function (): void {
    $uralt = $this->notifier->send(to: $this->ich, type: 'mention', title: 'Uralt, ungelesen');
    Notification::query()->whereKey($uralt->getKey())->update([
        Notification::column('created_at') => now()->subYear(),
    ]);

    $altGelesen = $this->notifier->send(to: $this->ich, type: 'mention', title: 'Uralt, gelesen');
    $altGelesen->markAsRead();
    Notification::query()->whereKey($altGelesen->getKey())->update([
        Notification::column('created_at') => now()->subYear(),
    ]);

    $titel = collect($this->feed->for($this->ich)['items'])->pluck('title')->all();

    expect($titel)->toBe(['Uralt, ungelesen']);
});

it('deckelt die Liste', function (): void {
    config()->set('notifications.feed.max_items', 3);

    foreach (range(1, 10) as $i) {
        $this->notifier->send(to: $this->ich, type: 'mention', title: "Meldung {$i}");
    }

    expect($this->feed->for($this->ich)['items'])->toHaveCount(3)
        // Die Zahl am Abzeichen bleibt ehrlich, auch wenn die Liste gedeckelt ist.
        ->and($this->feed->for($this->ich)['unread_count'])->toBe(10);
});

it('nimmt Beschriftung, Symbol und Farbe aus dem Typ-Register', function (): void {
    app(TypeRegistry::class)->register(
        new NotificationType('task.assigned', 'Aufgabe zugewiesen', 'user-plus', 'blue')
    );

    $this->notifier->send(to: $this->ich, type: 'task.assigned', title: 'Zugewiesen');

    $zeile = $this->feed->for($this->ich)['items'][0];

    expect($zeile['type_label'])->toBe('Aufgabe zugewiesen')
        ->and($zeile['icon'])->toBe('user-plus')
        ->and($zeile['color'])->toBe('blue');
});

it('bricht nicht an einem Gegenstand, dessen Klasse es nicht mehr gibt', function (): void {
    // Unter den Altbestaenden liegen Klassen, die aus dem Code verschwunden
    // sind. Ein eager geladenes `morphTo` wuerde hier werfen und dem Leser die
    // ganze Liste nehmen — nicht nur diese eine Zeile.
    Notification::create([
        Notification::column('user_id') => $this->ich->id,
        Notification::column('type') => 'legacy',
        Notification::column('title') => 'Aus einer frueheren Zeit',
        Notification::column('notifiable_type') => 'App\\Models\\GibtEsNichtMehr',
        Notification::column('notifiable_id') => 99,
    ]);

    $liste = $this->feed->for($this->ich);

    expect($liste['items'])->toHaveCount(1)
        ->and($liste['items'][0]['subject_type'])->toBe('App\\Models\\GibtEsNichtMehr');
});

it('laesst sich das Buendeln abschalten', function (): void {
    config()->set('notifications.feed.bundle', false);
    $widget = Widget::create(['title' => 'Server A']);

    foreach (range(1, 3) as $i) {
        $this->notifier->send(
            to: $this->ich, type: 'infra.unreachable',
            title: 'Server nicht erreichbar', subject: $widget,
        );
    }

    expect($this->feed->for($this->ich)['items'])->toHaveCount(3);
});

it('zaehlt ungelesen ohne die ganze Liste aufzubauen', function (): void {
    foreach (range(1, 5) as $i) {
        $this->notifier->send(to: $this->ich, type: 'mention', title: "M{$i}");
    }
    $this->notifier->send(to: $this->ich, type: 'mention', title: 'Gelesen')->markAsRead();

    expect($this->feed->unreadCount($this->ich))->toBe(5);
});

/* ------------------------------------------------- Herkunft */

it('nennt die Herkunft einer gespiegelten Meldung', function (): void {
    // Ohne diese Angabe steht in jedem System alles nebeneinander und es ist
    // nicht mehr erkennbar, wo etwas passiert ist.
    $this->notifier->send(to: $this->ich, type: 'mention', title: 'Aus dem Manager', data: [
        'mirror_origin' => 'peppermint-manager',
    ]);

    expect($this->feed->for($this->ich)['items'][0]['source'])->toBe('peppermint-manager');
});

it('laesst die Herkunft einer eigenen Meldung leer', function (): void {
    // `null` heisst „von hier". Den eigenen Produkt-Slug einzusetzen waere
    // bequemer, machte aber jede Zeile zu einer fremden — und die Ansicht
    // schriebe an JEDE ein Herkunfts-Abzeichen.
    $this->notifier->send(to: $this->ich, type: 'mention', title: 'Von hier');

    expect($this->feed->for($this->ich)['items'][0]['source'])->toBeNull();
});
