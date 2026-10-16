<?php

use Illuminate\Support\Facades\Schema;
use Peppermint\Notifications\Models\Notification;
use Peppermint\Notifications\Tests\Support\User;
use Peppermint\Notifications\Tests\Support\Widget;
use Peppermint\Notifications\Types\NotificationType;
use Peppermint\Notifications\Types\TypeRegistry;

beforeEach(function (): void {
    $this->empfaenger = User::create(['name' => 'Empfaenger']);
    $this->ausloeser = User::create(['name' => 'Ausloeser']);
});

function meldung(array $felder = []): Notification
{
    return Notification::create(array_merge([
        'user_id' => test()->empfaenger->id,
        'type' => 'task.assigned',
        'title' => 'Aufgabe zugewiesen',
    ], $felder));
}

it('legt eine Meldung an und liest sie ueber die Paketfelder', function (): void {
    $m = meldung(['message' => 'Du wurdest zugewiesen.', 'link' => '/tasks/1']);

    expect($m->field('title'))->toBe('Aufgabe zugewiesen')
        ->and($m->field('message'))->toBe('Du wurdest zugewiesen.')
        ->and($m->field('link'))->toBe('/tasks/1')
        ->and($m->isRead())->toBeFalse();
});

it('markiert als gelesen und setzt dabei den Zeitpunkt', function (): void {
    $m = meldung();

    expect($m->markAsRead())->toBeTrue()
        ->and($m->fresh()->isRead())->toBeTrue()
        ->and($m->fresh()->field('read_at'))->not->toBeNull();
});

it('markiert eine bereits gelesene Meldung nicht erneut', function (): void {
    $m = meldung();
    $m->markAsRead();

    $zeitpunkt = $m->fresh()->field('read_at');

    expect($m->fresh()->markAsRead())->toBeFalse()
        ->and($m->fresh()->field('read_at')->equalTo($zeitpunkt))->toBeTrue();
});

it('findet ungelesene Meldungen einer Person', function (): void {
    meldung();
    meldung()->markAsRead();
    Notification::create(['user_id' => $this->ausloeser->id, 'type' => 'mention', 'title' => 'Fremd']);

    expect(Notification::forUser($this->empfaenger->id)->unread()->count())->toBe(1)
        ->and(Notification::forUser($this->empfaenger->id)->count())->toBe(2);
});

it('findet die bestehende ungelesene Meldung am selben Gegenstand', function (): void {
    // Das ist die Abfrage hinter der rollenden Sammelmeldung: Gibt es schon
    // eine, die ich aktualisieren kann, statt eine zweite anzulegen?
    $widget = Widget::create(['title' => 'Unterhaltung']);

    meldung([
        'type' => 'chat.message',
        'notifiable_type' => Widget::class,
        'notifiable_id' => $widget->id,
    ]);

    $treffer = Notification::forUser($this->empfaenger->id)
        ->ofType('chat.message')
        ->unread()
        ->about(Widget::class, $widget->id)
        ->first();

    expect($treffer)->not->toBeNull();
});

it('loest den Gegenstand auf, wenn man ihn ausdruecklich anfordert', function (): void {
    $widget = Widget::create(['title' => 'Aufgabe 5']);

    $m = meldung(['notifiable_type' => Widget::class, 'notifiable_id' => $widget->id]);

    expect($m->notifiable)->toBeInstanceOf(Widget::class)
        ->and($m->notifiable->title)->toBe('Aufgabe 5');
});

it('kommt ohne Gegenstand aus — "Aufgabe geloescht" hat keinen mehr', function (): void {
    $m = meldung(['type' => 'task.deleted', 'title' => 'Aufgabe geloescht']);

    expect($m->field('notifiable_type'))->toBeNull()
        ->and($m->notifiable)->toBeNull();
});

it('kennt den Ausloeser', function (): void {
    $m = meldung(['triggered_by' => $this->ausloeser->id]);

    expect($m->triggeredBy->name)->toBe('Ausloeser');
});

it('wandelt data in ein Array um', function (): void {
    $m = meldung(['data' => ['anzahl' => 3, 'konto' => 'info@beispiel.de']]);

    expect($m->fresh()->field('data'))->toBe(['anzahl' => 3, 'konto' => 'info@beispiel.de']);
});

it('nimmt Symbol und Farbe aus der angemeldeten Art', function (): void {
    app(TypeRegistry::class)->register(
        new NotificationType('task.assigned', 'Aufgabe zugewiesen', 'user-plus', 'blue')
    );

    $m = meldung();

    expect($m->iconName())->toBe('user-plus')
        ->and($m->colorName())->toBe('blue');
});

it('laesst ein Symbol an der Meldung die Vorgabe der Art schlagen', function (): void {
    app(TypeRegistry::class)->register(
        new NotificationType('task.assigned', 'Aufgabe zugewiesen', 'user-plus', 'blue')
    );

    $m = meldung(['icon' => 'alarm-clock']);

    expect($m->iconName())->toBe('alarm-clock')
        // Die Farbe bleibt die der Art — nur das Symbol weicht ab.
        ->and($m->colorName())->toBe('blue');
});

it('zeigt fuer eine nicht angemeldete Art den Rueckfall statt zu scheitern', function (): void {
    $m = meldung(['type' => 'nie.angemeldet']);

    expect($m->iconName())->toBe('bell')
        ->and($m->colorName())->toBe('gray')
        ->and($m->typeDefinition()->label)->toBe('Benachrichtigung');
});

it('legt die Indizes an, auf denen Glocke und Sammelmeldung laufen', function (): void {
    // Eine Mutationsprobe auf die Migration: Ohne diese beiden Indizes liefe
    // die Glocke bei 17.000 Zeilen ueber einen vollen Durchlauf. Dass der Test
    // das Schema liest statt die Migration, ist Absicht — er misst das
    // Ergebnis, nicht die Absicht.
    expect(Schema::hasTable('notifications'))->toBeTrue();

    $indizes = collect(Schema::getIndexes('notifications'))->pluck('name');

    expect($indizes)->toContain('notifications_bell_idx')
        ->and($indizes)->toContain('notifications_rolling_idx');
});

it('laesst eine Farbe an der Meldung die Vorgabe der Art schlagen', function (): void {
    // Im Manager bestimmt die ART die Farbe, im AI Brain der SCHWEREGRAD:
    // Dieselbe Art meldet "Server nicht erreichbar" (rot) und "Server wieder
    // da" (gruen). Beides ist richtig, also kann beides gesetzt werden.
    app(TypeRegistry::class)->register(
        new NotificationType('infra.server', 'Server-Zustand', 'server', 'red')
    );

    expect(meldung(['type' => 'infra.server'])->colorName())->toBe('red')
        ->and(meldung(['type' => 'infra.server', 'color' => 'green'])->colorName())->toBe('green');
});
