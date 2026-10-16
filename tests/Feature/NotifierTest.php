<?php

use Illuminate\Support\Facades\Event;
use Peppermint\Notifications\Delivery\Notifier;
use Peppermint\Notifications\Events\NotificationCreated;
use Peppermint\Notifications\Events\NotificationRolled;
use Peppermint\Notifications\Models\Notification;
use Peppermint\Notifications\Tests\Support\User;
use Peppermint\Notifications\Tests\Support\Widget;

beforeEach(function (): void {
    $this->notifier = app(Notifier::class);
    $this->empfaenger = User::create(['name' => 'Empfaenger']);
    $this->ausloeser = User::create(['name' => 'Ausloeser']);
});

/* ------------------------------------------------------------- send() */

it('stellt eine Meldung zu', function (): void {
    $m = $this->notifier->send(
        to: $this->empfaenger,
        type: 'task.assigned',
        title: 'Aufgabe zugewiesen',
        message: 'Du wurdest zugewiesen.',
        link: '/tasks/1',
    );

    expect($m)->not->toBeNull()
        ->and($m->field('user_id'))->toBe($this->empfaenger->id)
        ->and($m->field('title'))->toBe('Aufgabe zugewiesen')
        ->and($m->field('link'))->toBe('/tasks/1')
        ->and($m->isRead())->toBeFalse();
});

it('benachrichtigt niemanden ueber die eigene Handlung', function (): void {
    $m = $this->notifier->send(
        to: $this->empfaenger,
        type: 'task.assigned',
        title: 'Aufgabe zugewiesen',
        triggeredBy: $this->empfaenger,
    );

    expect($m)->toBeNull()
        ->and(Notification::count())->toBe(0);
});

it('benachrichtigt sehr wohl, wenn ein ANDERER ausgeloest hat', function (): void {
    $m = $this->notifier->send(
        to: $this->empfaenger,
        type: 'task.assigned',
        title: 'Aufgabe zugewiesen',
        triggeredBy: $this->ausloeser,
    );

    expect($m)->not->toBeNull()
        ->and($m->field('triggered_by'))->toBe($this->ausloeser->id);
});

it('benachrichtigt auch ohne bekannten Ausloeser — ein Zeitplan hat keinen', function (): void {
    $m = $this->notifier->send(
        to: $this->empfaenger,
        type: 'task.overdue',
        title: 'Aufgabe ueberfaellig',
    );

    expect($m)->not->toBeNull();
});

it('nimmt den Gegenstand entgegen und haengt die Meldung daran', function (): void {
    $widget = Widget::create(['title' => 'Aufgabe 5']);

    $m = $this->notifier->send(
        to: $this->empfaenger,
        type: 'task.assigned',
        title: 'Zugewiesen',
        subject: $widget,
    );

    expect($m->field('notifiable_type'))->toBe(Widget::class)
        ->and($m->field('notifiable_id'))->toBe($widget->id);
});

it('meldet eine neue Zustellung als Ereignis', function (): void {
    Event::fake([NotificationCreated::class]);

    $this->notifier->send(to: $this->empfaenger, type: 'mention', title: 'Erwaehnt');

    Event::assertDispatched(NotificationCreated::class);
});

it('meldet KEIN Ereignis, wenn der Einmal-Riegel gegriffen hat', function (): void {
    Event::fake([NotificationCreated::class]);

    $this->notifier->send(
        to: $this->empfaenger,
        type: 'mention',
        title: 'Erwaehnt',
        triggeredBy: $this->empfaenger,
    );

    Event::assertNotDispatched(NotificationCreated::class);
});

it('laesst data leer, wenn nichts uebergeben wurde — kein leeres Objekt in der Spalte', function (): void {
    $m = $this->notifier->send(to: $this->empfaenger, type: 'mention', title: 'Erwaehnt');

    expect($m->fresh()->field('data'))->toBeNull();
});

/* ---------------------------------------------------------- rolling() */

it('legt beim ersten Mal eine neue Meldung an', function (): void {
    $widget = Widget::create(['title' => 'Unterhaltung']);

    $m = $this->notifier->rolling(
        to: $this->empfaenger,
        type: 'chat.message',
        title: 'Unterhaltung',
        message: fn (int $n): string => "{$n} neue Nachrichten",
        subject: $widget,
    );

    expect(Notification::count())->toBe(1)
        ->and($m->field('message'))->toBe('1 neue Nachrichten')
        ->and($m->field('data')['rolled_count'])->toBe(1);
});

it('schreibt beim zweiten Mal dieselbe Zeile fort, statt eine zweite anzulegen', function (): void {
    $widget = Widget::create(['title' => 'Unterhaltung']);

    $rollen = fn () => $this->notifier->rolling(
        to: $this->empfaenger,
        type: 'chat.message',
        title: 'Unterhaltung',
        message: fn (int $n): string => "{$n} neue Nachrichten",
        subject: $widget,
    );

    $rollen();
    $rollen();
    $rollen();

    expect(Notification::count())->toBe(1)
        ->and(Notification::first()->field('message'))->toBe('3 neue Nachrichten')
        ->and(Notification::first()->field('data')['rolled_count'])->toBe(3);
});

it('zaehlt im data-Feld und nicht aus dem Text — der Wortlaut darf sich aendern', function (): void {
    // Der Manager liest den Zaehler heute per regulaerem Ausdruck aus der
    // Nachricht zurueck. Aendert jemand den Wortlaut — oder uebersetzt das
    // Produkt —, faengt die Zaehlung still wieder bei zwei an.
    $widget = Widget::create(['title' => 'Unterhaltung']);

    $this->notifier->rolling(
        to: $this->empfaenger, type: 'chat.message', title: 'U',
        message: fn (int $n): string => "{$n} neue Nachrichten", subject: $widget,
    );

    $zweite = $this->notifier->rolling(
        to: $this->empfaenger, type: 'chat.message', title: 'U',
        // Voellig anderer Wortlaut, sogar ohne Ziffer.
        message: fn (int $n): string => 'Da ist etwas Neues', subject: $widget,
    );

    expect($zweite->field('data')['rolled_count'])->toBe(2);
});

it('beginnt nach dem Lesen einen neuen Zyklus', function (): void {
    $widget = Widget::create(['title' => 'Unterhaltung']);

    $erste = $this->notifier->rolling(
        to: $this->empfaenger, type: 'chat.message', title: 'U',
        message: fn (int $n): string => "{$n}", subject: $widget,
    );
    $erste->markAsRead();

    $zweite = $this->notifier->rolling(
        to: $this->empfaenger, type: 'chat.message', title: 'U',
        message: fn (int $n): string => "{$n}", subject: $widget,
    );

    expect(Notification::count())->toBe(2)
        ->and($zweite->field('data')['rolled_count'])->toBe(1)
        ->and($zweite->getKey())->not->toBe($erste->getKey());
});

it('rollt nicht ueber verschiedene Gegenstaende hinweg', function (): void {
    $eins = Widget::create(['title' => 'Unterhaltung A']);
    $zwei = Widget::create(['title' => 'Unterhaltung B']);

    foreach ([$eins, $zwei] as $widget) {
        $this->notifier->rolling(
            to: $this->empfaenger, type: 'chat.message', title: 'U',
            message: fn (int $n): string => "{$n}", subject: $widget,
        );
    }

    expect(Notification::count())->toBe(2);
});

it('rollt nicht ueber verschiedene Personen hinweg', function (): void {
    $widget = Widget::create(['title' => 'Unterhaltung']);

    foreach ([$this->empfaenger, $this->ausloeser] as $person) {
        $this->notifier->rolling(
            to: $person, type: 'chat.message', title: 'U',
            message: fn (int $n): string => "{$n}", subject: $widget,
        );
    }

    expect(Notification::count())->toBe(2);
});

it('sortiert die fortgeschriebene Zeile wieder nach oben', function (): void {
    $widget = Widget::create(['title' => 'Unterhaltung']);

    $m = $this->notifier->rolling(
        to: $this->empfaenger, type: 'chat.message', title: 'U',
        message: fn (int $n): string => "{$n}", subject: $widget,
    );

    Notification::query()->whereKey($m->getKey())->update([
        Notification::column('created_at') => now()->subDays(3),
    ]);

    $this->notifier->rolling(
        to: $this->empfaenger, type: 'chat.message', title: 'U',
        message: fn (int $n): string => "{$n}", subject: $widget,
    );

    expect($m->fresh()->field('created_at')->isToday())->toBeTrue();
});

it('meldet das Fortschreiben als EIGENES Ereignis — sonst pusht es zweimal', function (): void {
    Event::fake([NotificationCreated::class, NotificationRolled::class]);
    $widget = Widget::create(['title' => 'Unterhaltung']);

    $rollen = fn () => $this->notifier->rolling(
        to: $this->empfaenger, type: 'chat.message', title: 'U',
        message: fn (int $n): string => "{$n}", subject: $widget,
    );

    $rollen();
    $rollen();

    // Einmal angelegt, einmal fortgeschrieben — nicht zweimal angelegt.
    Event::assertDispatchedTimes(NotificationCreated::class, 1);
    Event::assertDispatchedTimes(NotificationRolled::class, 1);
});

it('nennt im Fortschreib-Ereignis die Gesamtzahl', function (): void {
    Event::fake([NotificationRolled::class]);
    $widget = Widget::create(['title' => 'U']);

    $rollen = fn () => $this->notifier->rolling(
        to: $this->empfaenger, type: 'chat.message', title: 'U',
        message: fn (int $n): string => "{$n}", subject: $widget,
    );

    $rollen();
    $rollen();
    $rollen();

    Event::assertDispatched(NotificationRolled::class, fn ($e): bool => $e->count === 3);
});

it('greift auch beim Rollen den Einmal-Riegel', function (): void {
    $widget = Widget::create(['title' => 'U']);

    $m = $this->notifier->rolling(
        to: $this->empfaenger, type: 'chat.message', title: 'U',
        message: fn (int $n): string => "{$n}", subject: $widget,
        triggeredBy: $this->empfaenger,
    );

    expect($m)->toBeNull()
        ->and(Notification::count())->toBe(0);
});

it('zaehlt die eigene Handlung NICHT in eine bestehende Zeile hoch', function (): void {
    // Der Fall, den der Test darueber NICHT trifft: Ohne bestehende Meldung
    // faengt schon `send()` den eigenen Ausloeser ab, und der Riegel in
    // `rolling()` wird nie gebraucht. Er wird erst gebraucht, wenn eine
    // ungelesene Zeile dasteht — sonst zaehlt die eigene Chat-Nachricht den
    // eigenen Zaehler hoch.
    //
    // Per Mutationsprobe belegt: Nimmt man den Riegel aus `rolling()` heraus,
    // faellt genau dieser Test und sonst keiner.
    $widget = Widget::create(['title' => 'Unterhaltung']);

    // Jemand anders schreibt: Zeile entsteht, Zaehler 1.
    $this->notifier->rolling(
        to: $this->empfaenger, type: 'chat.message', title: 'U',
        message: fn (int $n): string => "{$n} neue Nachrichten", subject: $widget,
        triggeredBy: $this->ausloeser,
    );

    // Jetzt schreibe ICH selbst in dieselbe Unterhaltung.
    $eigene = $this->notifier->rolling(
        to: $this->empfaenger, type: 'chat.message', title: 'U',
        message: fn (int $n): string => "{$n} neue Nachrichten", subject: $widget,
        triggeredBy: $this->empfaenger,
    );

    expect($eigene)->toBeNull()
        ->and(Notification::count())->toBe(1)
        ->and(Notification::first()->field('data')['rolled_count'])->toBe(1)
        ->and(Notification::first()->field('message'))->toBe('1 neue Nachrichten');
});

it('nimmt groessere Schritte als eins entgegen — fuenf neue Mails auf einmal', function (): void {
    $widget = Widget::create(['title' => 'Postfach']);

    $this->notifier->rolling(
        to: $this->empfaenger, type: 'email.new', title: 'Neue E-Mail',
        message: fn (int $n): string => "{$n} neue E-Mails", subject: $widget, increment: 5,
    );
    $m = $this->notifier->rolling(
        to: $this->empfaenger, type: 'email.new', title: 'Neue E-Mail',
        message: fn (int $n): string => "{$n} neue E-Mails", subject: $widget, increment: 3,
    );

    expect($m->field('message'))->toBe('8 neue E-Mails');
});

it('rollt auch ohne Gegenstand — der Typ allein traegt dann', function (): void {
    $rollen = fn () => $this->notifier->rolling(
        to: $this->empfaenger, type: 'infra.queue', title: 'Warteschlange',
        message: fn (int $n): string => "{$n} Meldungen",
    );

    $rollen();
    $rollen();

    expect(Notification::count())->toBe(1)
        ->and(Notification::first()->field('message'))->toBe('2 Meldungen');
});
