<?php

use Illuminate\Support\Facades\DB;
use Peppermint\Notifications\Delivery\Notifier;
use Peppermint\Notifications\Models\Notification;
use Peppermint\Notifications\Preferences\Channel;
use Peppermint\Notifications\Preferences\Level;
use Peppermint\Notifications\Preferences\NotificationPreference;
use Peppermint\Notifications\Preferences\PreferenceResolver;
use Peppermint\Notifications\Tests\Support\User;
use Peppermint\Notifications\Types\TypeRegistry;

/**
 * Wer bekommt was, auf welchem Weg (#7994).
 *
 * Drei Ebenen, und die Reihenfolge ist der Kern: die Natur des Weges, dann
 * die Vorgabe des Produkts, dann die Entscheidung des Menschen.
 */
beforeEach(function (): void {
    app(TypeRegistry::class)->registerMany([
        ['key' => 'task.assigned', 'label' => 'Aufgabe zugewiesen', 'icon' => 'user-plus', 'color' => 'blue'],
        ['key' => 'invoice.approval', 'label' => 'Rechnungsfreigabe', 'icon' => 'euro', 'color' => 'amber'],
    ]);

    $this->ich = User::create(['name' => 'Bastian']);
    $this->loeser = app(PreferenceResolver::class);
});

/* --------------------------------------------------------- die Vorgaben */

it('sammelt E-Mails per Vorgabe, statt sie einzeln zu schicken', function (): void {
    // Der eigentliche Grund fuer drei Stufen: Push und Glocke kosten eine
    // Zeile, eine E-Mail kostet eine E-Mail.
    expect($this->loeser->vorgabe('task.assigned', Channel::Email))->toBe(Level::Digest)
        ->and($this->loeser->vorgabe('task.assigned', Channel::InApp))->toBe(Level::Instant);
});

it('laesst das Produkt die Vorgabe je Art ueberschreiben', function (): void {
    config()->set('notifications.preferences.per_type.invoice.approval.email', 'instant');

    expect($this->loeser->vorgabe('invoice.approval', Channel::Email))->toBe(Level::Instant)
        // Die uebrigen Arten bleiben, wo sie waren.
        ->and($this->loeser->vorgabe('task.assigned', Channel::Email))->toBe(Level::Digest);
});

it('faellt bei einem unsinnigen Wert auf die Natur des Weges zurueck', function (): void {
    // Die Stufe kommt aus einer Konfiguration, die ein Mensch tippt. Eine
    // Ausnahme mitten in der Zustellung waere der falsche Preis dafuer.
    config()->set('notifications.preferences.defaults.email', 'vielleicht');

    expect($this->loeser->vorgabe('task.assigned', Channel::Email))->toBe(Level::Digest);
});

/* ------------------------------------------------ die eigene Entscheidung */

it('schlaegt die Vorgabe mit der eigenen Entscheidung', function (): void {
    $this->loeser->set($this->ich, 'task.assigned', [Channel::Email->value => Level::Instant]);

    expect($this->loeser->level($this->ich, 'task.assigned', Channel::Email))->toBe(Level::Instant);
});

it('legt fuer „folgt der Vorgabe" gar keine Zeile an', function (): void {
    // Sonst muesste jede neue Art fuer jeden Menschen nachgetragen werden —
    // und eine geaenderte Vorgabe erreichte niemanden mehr.
    $this->loeser->set($this->ich, 'task.assigned', [
        Channel::Email->value => null,
        Channel::Push->value => null,
        Channel::InApp->value => null,
    ]);

    expect(NotificationPreference::query()->count())->toBe(0);
});

it('loescht eine Zeile, die auf „folgt der Vorgabe" zurueckgesetzt wurde', function (): void {
    $this->loeser->set($this->ich, 'task.assigned', [Channel::Email->value => Level::Off]);
    expect(NotificationPreference::query()->count())->toBe(1);

    $this->loeser->set($this->ich, 'task.assigned', [Channel::Email->value => null]);

    expect(NotificationPreference::query()->count())->toBe(0);
});

it('folgt nach dem Zuruecksetzen wieder einer GEAENDERTEN Vorgabe', function (): void {
    // Der Unterschied zwischen „zurueckgesetzt" und „auf den Vorgabewert
    // gestellt". Ohne ihn friert jedes Speichern die Vorgabe von heute ein.
    $this->loeser->set($this->ich, 'task.assigned', [Channel::Email->value => null]);

    config()->set('notifications.preferences.defaults.email', 'off');

    expect($this->loeser->level($this->ich, 'task.assigned', Channel::Email))->toBe(Level::Off);
});

it('haelt die Entscheidungen zweier Menschen auseinander', function (): void {
    $andere = User::create(['name' => 'Andere']);

    $this->loeser->set($this->ich, 'task.assigned', [Channel::Push->value => Level::Off]);

    expect($this->loeser->level($this->ich, 'task.assigned', Channel::Push))->toBe(Level::Off)
        ->and($this->loeser->level($andere, 'task.assigned', Channel::Push))->toBe(Level::Instant);
});

/* ------------------------------------------------------------ die Matrix */

it('zeigt eine Zeile je angemeldeter Art, nicht je gespeicherter', function (): void {
    // Was neu angemeldet wurde, soll sofort dastehen; was kein Produkt
    // angemeldet hat, soll gar nicht erscheinen.
    $matrix = $this->loeser->matrix($this->ich);

    expect($matrix)->toHaveCount(2)
        // Nach der Beschriftung sortiert, nicht nach dem Schluessel: „Aufgabe
        // zugewiesen" vor „Rechnungsfreigabe", obwohl `invoice.approval`
        // frueher im Alphabet liegt.
        ->and(array_column($matrix, 'label'))->toBe(['Aufgabe zugewiesen', 'Rechnungsfreigabe']);
});

it('unterscheidet in der Matrix „selbst entschieden" von „folgt der Vorgabe"', function (): void {
    // Die Maske zeigt sonst einen Wert, der aussieht wie eine Entscheidung.
    $this->loeser->set($this->ich, 'task.assigned', [Channel::Push->value => Level::Off]);

    $zeile = collect($this->loeser->matrix($this->ich))->firstWhere('type', 'task.assigned');

    expect($zeile['channels']['push'])->toBe(['level' => 'off', 'eigen' => true, 'vorgabe' => 'instant'])
        ->and($zeile['channels']['email'])->toBe(['level' => 'digest', 'eigen' => false, 'vorgabe' => 'digest']);
});

/* ------------------------------------------------- der Riegel beim Zustellen */

it('legt gar keine Zeile an, wenn die Glocke fuer diese Art aus ist', function (): void {
    // „Aus" heisst bei einem Posteingang nicht „sammle es und zeig es mir
    // nicht". Eine Tabelle, die mitschreibt, was niemand je sieht, ist die
    // Halde, gegen die dieses Paket gebaut wurde.
    $this->loeser->set($this->ich, 'task.assigned', [Channel::InApp->value => Level::Off]);

    $meldung = app(Notifier::class)->send(to: $this->ich, type: 'task.assigned', title: 'Probe');

    expect($meldung)->toBeNull()
        ->and(Notification::query()->count())->toBe(0);
});

it('laesst die uebrigen Arten davon unberuehrt', function (): void {
    $this->loeser->set($this->ich, 'task.assigned', [Channel::InApp->value => Level::Off]);

    app(Notifier::class)->send(to: $this->ich, type: 'invoice.approval', title: 'Probe');

    expect(Notification::query()->count())->toBe(1);
});

it('verriegelt auch die rollende Sammelmeldung', function (): void {
    // Sonst haette „aus" eine Luecke genau bei den Arten, die am haeufigsten
    // kommen — rollende Meldungen sind die lauten.
    $this->loeser->set($this->ich, 'task.assigned', [Channel::InApp->value => Level::Off]);

    app(Notifier::class)->rolling(
        to: $this->ich, type: 'task.assigned',
        title: 'Aufgaben',
        message: fn (int $n): string => "{$n} Aufgaben",
    );

    expect(Notification::query()->count())->toBe(0);
});

it('laesst stehen, was vor dem Abschalten schon da war', function (): void {
    app(Notifier::class)->send(to: $this->ich, type: 'task.assigned', title: 'Vorher');

    $this->loeser->set($this->ich, 'task.assigned', [Channel::InApp->value => Level::Off]);

    expect(Notification::query()->count())->toBe(1);
});

it('liest die Einstellungen eines Menschen nur einmal je Aufruf', function (): void {
    // Eine Zustellung an zwanzig Menschen soll nicht zwanzig Mal dieselbe
    // Tabelle lesen — und ein Rundbrief keine hunderte Abfragen ausloesen.
    $this->loeser->level($this->ich, 'task.assigned', Channel::InApp);

    $abfragen = 0;
    DB::listen(function () use (&$abfragen): void {
        $abfragen++;
    });

    $this->loeser->level($this->ich, 'task.assigned', Channel::Push);
    $this->loeser->level($this->ich, 'invoice.approval', Channel::Email);

    expect($abfragen)->toBe(0);
});

it('vergisst den Zwischenspeicher nach einer Aenderung', function (): void {
    // Sonst gilt im selben Aufruf noch die alte Einstellung — und die Maske
    // zeigt nach dem Speichern den Stand von vorher.
    expect($this->loeser->level($this->ich, 'task.assigned', Channel::Push))->toBe(Level::Instant);

    $this->loeser->set($this->ich, 'task.assigned', [Channel::Push->value => Level::Off]);

    expect($this->loeser->level($this->ich, 'task.assigned', Channel::Push))->toBe(Level::Off);
});
