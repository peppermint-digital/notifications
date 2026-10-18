<?php

use Illuminate\Support\Facades\Event;
use Peppermint\Notifications\Bridge\NativeBridge;
use Peppermint\Notifications\Events\NotificationCreated;
use Peppermint\Notifications\Models\Notification;
use Peppermint\Notifications\Preferences\Channel;
use Peppermint\Notifications\Preferences\Level;
use Peppermint\Notifications\Preferences\PreferenceResolver;
use Peppermint\Notifications\Tests\Support\User;
use Peppermint\Notifications\Types\TypeRegistry;

/**
 * Die Brücke für Produkte, die am Notifier vorbei schreiben (#1038).
 *
 * Gemessen am 18.10.2026: Seit dem Einschalten der Spiegelung war beim Hub
 * NULL Meldungen aus einem Produkt angekommen — rund 188 pro Woche blieben
 * liegen. Alles, was dieses Paket über eine neue Meldung tut, hängt an
 * `NotificationCreated`, und das feuert nur der Notifier. Die Produkte
 * benutzen ihn nicht.
 */
beforeEach(function (): void {
    app(TypeRegistry::class)->registerMany([
        ['key' => 'note.shared', 'label' => 'Notiz geteilt', 'icon' => 'datei', 'color' => 'blue'],
    ]);

    $this->ich = User::create(['name' => 'Ich', 'email' => 'ich@beispiel.test']);
    $this->bruecke = app(NativeBridge::class);
});

it('feuert das Ereignis fuer eine Meldung, die am Notifier vorbei entstand', function (): void {
    Event::fake([NotificationCreated::class]);

    $meldung = Notification::create([
        'user_id' => $this->ich->id,
        'type' => 'note.shared',
        'title' => 'Notiz geteilt',
    ]);

    $this->bruecke->angelegt($meldung->getKey());

    // Dasselbe Ereignis, das der Notifier feuern wuerde. Damit greift ALLES,
    // was daran haengt — Spiegelung, Sofort-Mail, und was dazukommt.
    Event::assertDispatched(NotificationCreated::class);
});

it('bleibt still, wenn es die Meldung nicht mehr gibt', function (): void {
    Event::fake([NotificationCreated::class]);

    $this->bruecke->angelegt(999999);

    Event::assertNotDispatched(NotificationCreated::class);
});

it('bricht nicht ab, wenn etwas schiefgeht', function (): void {
    // Fail-open: Dass eine Meldung den Hub nicht erreicht, darf den Vorgang
    // nicht abbrechen, der sie ausgeloest hat.
    $this->bruecke->angelegt(null);

    expect(true)->toBeTrue();
});

/* ------------------------------------------------------- der Riegel */

it('laesst durch, was der Mensch sehen will', function (): void {
    expect($this->bruecke->willSehen($this->ich->id, 'note.shared'))->toBeTrue();
});

it('haelt zurueck, was auf „aus" steht', function (): void {
    app(PreferenceResolver::class)->set($this->ich, 'note.shared', [Channel::InApp->value => Level::Off]);

    expect($this->bruecke->willSehen($this->ich->id, 'note.shared'))->toBeFalse();
});

it('laesst durch, was sich nicht zuordnen laesst', function (): void {
    // Ohne Empfaenger oder ohne erkennbare Art laesst sich nichts
    // abschalten. Lautlos verschlucken waere der schlechtere Ausgang.
    expect($this->bruecke->willSehen(null, 'note.shared'))->toBeTrue()
        ->and($this->bruecke->willSehen($this->ich->id, null))->toBeTrue()
        ->and($this->bruecke->willSehen($this->ich->id, ''))->toBeTrue();
});

/* --------------------------------------------- den Empfaenger finden */

it('findet den Empfaenger in der Manager-Form', function (): void {
    $meldung = Notification::create(['user_id' => $this->ich->id, 'type' => 'note.shared', 'title' => 'X']);

    expect($this->bruecke->empfaengerAus($meldung))->toBe($this->ich->id);
});

it('findet ihn auch in der Standardform, wo er notifiable_id heisst', function (): void {
    // In der Laravel-Standardform ist `notifiable_id` der EMPFAENGER, nicht
    // der Gegenstand. Wer das verwechselt, spiegelt an den Falschen.
    $meldung = new Notification;
    $meldung->setAttribute('notifiable_id', 4711);

    expect($this->bruecke->empfaengerAus($meldung))->toBe(4711);
});
