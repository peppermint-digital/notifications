<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Peppermint\Notifications\Delivery\Notifier;
use Peppermint\Notifications\Mail\EmailDelivery;
use Peppermint\Notifications\Mail\NotificationMail;
use Peppermint\Notifications\Preferences\Channel;
use Peppermint\Notifications\Preferences\Level;
use Peppermint\Notifications\Preferences\PreferenceResolver;
use Peppermint\Notifications\Tests\Support\User;
use Peppermint\Notifications\Types\TypeRegistry;

/**
 * Der E-Mail-Weg (#8165).
 *
 * Bis zum 18.10.2026 bot die Matrix drei Stufen fuer E-Mail an, und
 * `Channel::Email` wurde an keiner einzigen Stelle gelesen. Wer „Sofort"
 * stellte, bekam nichts — und glaubte, er haette es eingestellt.
 */
beforeEach(function (): void {
    Mail::fake();

    app(TypeRegistry::class)->registerMany([
        ['key' => 'task.assigned', 'label' => 'Aufgabe zugewiesen', 'icon' => 'user-plus', 'color' => 'blue'],
        ['key' => 'chat.message', 'label' => 'Neue Nachricht', 'icon' => 'chat', 'color' => 'gray'],
    ]);

    $this->ich = User::create(['name' => 'Bastian', 'email' => 'ich@beispiel.test']);
    $this->loeser = app(PreferenceResolver::class);
    $this->notifier = app(Notifier::class);
});

/* --------------------------------------------------------------- sofort */

it('schickt per Vorgabe gar nichts', function (): void {
    // Der teuerste Kanal gehoert zum Mitmachen, nicht zum Abbestellen.
    $this->notifier->send(to: $this->ich, type: 'task.assigned', title: 'Probe');

    Mail::assertNothingSent();
});

it('schickt sofort, wenn die Art auf „sofort" steht', function (): void {
    $this->loeser->set($this->ich, 'task.assigned', [Channel::Email->value => Level::Instant]);

    $this->notifier->send(to: $this->ich, type: 'task.assigned', title: 'Aufgabe zugewiesen');

    Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail): bool => $mail->hasTo('ich@beispiel.test')
        && count($mail->zeilen) === 1
        && $mail->zeilen[0]['title'] === 'Aufgabe zugewiesen');
});

it('schickt nichts fuer eine Art, die auf „Zusammenfassung" steht', function (): void {
    $this->loeser->set($this->ich, 'task.assigned', [Channel::Email->value => Level::Digest]);

    $this->notifier->send(to: $this->ich, type: 'task.assigned', title: 'Probe');

    Mail::assertNothingSent();
});

it('schickt keine zweite Mail, wenn eine Sammelmeldung fortgeschrieben wird', function (): void {
    // Eine rollende Meldung ist dieselbe Sache noch einmal. Eine zweite Mail
    // dafuer waere genau die Flut, gegen die das Rollen gebaut ist — und sie
    // traefe ausgerechnet die lautesten Arten.
    $this->loeser->set($this->ich, 'chat.message', [Channel::Email->value => Level::Instant]);

    $this->notifier->rolling(
        to: $this->ich, type: 'chat.message',
        title: 'Nachrichten', message: fn (int $n): string => "{$n} Nachrichten",
    );
    $this->notifier->rolling(
        to: $this->ich, type: 'chat.message',
        title: 'Nachrichten', message: fn (int $n): string => "{$n} Nachrichten",
    );

    Mail::assertSentCount(1);
});

it('schickt nichts an jemanden ohne Adresse', function (): void {
    $ohne = User::create(['name' => 'Ohne Adresse']);
    $this->loeser->set($ohne, 'task.assigned', [Channel::Email->value => Level::Instant]);

    $this->notifier->send(to: $ohne, type: 'task.assigned', title: 'Probe');

    Mail::assertNothingSent();
});

/* ---------------------------------------------------------- Sammel-Mail */

it('fasst in einer Mail zusammen, was seit dem letzten Versand auflief', function (): void {
    $this->loeser->set($this->ich, 'task.assigned', [Channel::Email->value => Level::Digest]);

    foreach (['Eins', 'Zwei', 'Drei'] as $titel) {
        $this->notifier->send(to: $this->ich, type: 'task.assigned', title: $titel);
    }

    expect(app(EmailDelivery::class)->sammeln($this->ich))->toBe(3);

    Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail): bool => count($mail->zeilen) === 3);
});

it('laesst Arten aus, die nicht auf „Zusammenfassung" stehen', function (): void {
    $this->loeser->set($this->ich, 'task.assigned', [Channel::Email->value => Level::Digest]);
    $this->loeser->set($this->ich, 'chat.message', [Channel::Email->value => Level::Off]);

    $this->notifier->send(to: $this->ich, type: 'task.assigned', title: 'Gehoert dazu');
    $this->notifier->send(to: $this->ich, type: 'chat.message', title: 'Gehoert nicht dazu');

    expect(app(EmailDelivery::class)->sammeln($this->ich))->toBe(1);
});

it('schickt dieselbe Meldung nicht zweimal', function (): void {
    $this->loeser->set($this->ich, 'task.assigned', [Channel::Email->value => Level::Digest]);
    $this->notifier->send(to: $this->ich, type: 'task.assigned', title: 'Einmal');

    app(EmailDelivery::class)->sammeln($this->ich);
    expect(app(EmailDelivery::class)->sammeln($this->ich))->toBe(0);

    Mail::assertSentCount(1);
});

it('schreibt den Zeitpunkt auch dann fort, wenn nichts dabei war', function (): void {
    // Sonst waechst das Fenster bei jemandem, der nur Arten mit „aus"
    // bekommt, unbegrenzt — und die erste passende Meldung braechte Monate
    // alte Begleitung mit.
    app(EmailDelivery::class)->sammeln($this->ich);

    $erster = DB::table('notification_digests')->where('user_id', $this->ich->id)->value('last_sent_at');

    expect($erster)->not->toBeNull();
});

it('nimmt nur die eigenen Meldungen in die Mail', function (): void {
    $andere = User::create(['name' => 'Andere', 'email' => 'andere@beispiel.test']);

    $this->loeser->set($this->ich, 'task.assigned', [Channel::Email->value => Level::Digest]);
    $this->loeser->set($andere, 'task.assigned', [Channel::Email->value => Level::Digest]);

    $this->notifier->send(to: $this->ich, type: 'task.assigned', title: 'Meine');
    $this->notifier->send(to: $andere, type: 'task.assigned', title: 'Fremde');

    expect(app(EmailDelivery::class)->sammeln($this->ich))->toBe(1);

    Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail): bool => $mail->zeilen[0]['title'] === 'Meine');
});

it('macht aus einem relativen Verweis einen absoluten', function (): void {
    // In einer Mail zeigt ein relativer Verweis ins Nichts.
    config()->set('app.url', 'https://crm.beispiel.test');
    $this->loeser->set($this->ich, 'task.assigned', [Channel::Email->value => Level::Instant]);

    $this->notifier->send(to: $this->ich, type: 'task.assigned', title: 'Probe', link: '/deals/5');

    Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail): bool => $mail->zeilen[0]['link'] === 'https://crm.beispiel.test/deals/5');
});

it('laesst einen absoluten Verweis in ein anderes System stehen', function (): void {
    config()->set('app.url', 'https://crm.beispiel.test');
    $this->loeser->set($this->ich, 'task.assigned', [Channel::Email->value => Level::Instant]);

    $this->notifier->send(
        to: $this->ich, type: 'task.assigned', title: 'Probe',
        link: 'https://pm.beispiel.test/tasks/5',
    );

    Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail): bool => $mail->zeilen[0]['link'] === 'https://pm.beispiel.test/tasks/5');
});

/* ------------------------------------------------------------- Betreff */

it('nimmt bei einer einzelnen Meldung deren Titel als Betreff', function (): void {
    // Wer die Zeile liest, weiss sofort, ob es ihn jetzt betrifft.
    $mail = new NotificationMail('Bastian', [['title' => 'Server nicht erreichbar']], 'AI Brain');

    expect($mail->envelope()->subject)->toBe('Server nicht erreichbar');
});

it('nennt bei mehreren die Anzahl', function (): void {
    $mail = new NotificationMail('Bastian', [['title' => 'A'], ['title' => 'B']], 'AI Brain');

    expect($mail->envelope()->subject)->toBe('2 neue Benachrichtigungen');
});

/* --------------------------------- das Fenster vor der Migration */

it('bricht nicht ab, wenn die Tabelle fuer die Versandzeitpunkte fehlt', function (): void {
    // Der Zeitplan laeuft im Fenster zwischen Deploy und Migration weiter.
    // Ohne Nachsicht braeche er jede Nacht mit einer Ausnahme ab — sichtbar
    // nur im Protokoll, das in so einem Fenster niemand liest.
    Schema::drop('notification_digests');

    $this->loeser->set($this->ich, 'task.assigned', [Channel::Email->value => Level::Digest]);
    $this->notifier->send(to: $this->ich, type: 'task.assigned', title: 'Probe');

    expect(app(EmailDelivery::class)->sammeln($this->ich))->toBe(0);

    Mail::assertNothingSent();
});

it('zaehlt im Probelauf die Mails, nicht die Menschen', function (): void {
    // „12 Menschen kaemen in Frage" klingt nach zwoelf Mails und meint oft
    // null. Eine Zahl, die nach Versand klingt und keinen meint, ist genau
    // die Art Auskunft, nach der jemand den Zeitplan abschaltet.
    $this->notifier->send(to: $this->ich, type: 'task.assigned', title: 'Keine Mail, Vorgabe ist aus');

    expect(app(EmailDelivery::class)->vorschau($this->ich))->toBe(0);

    $this->loeser->set($this->ich, 'task.assigned', [Channel::Email->value => Level::Digest]);

    expect(app(EmailDelivery::class)->vorschau($this->ich))->toBe(1);
});

it('schickt im Probelauf nichts und merkt sich auch nichts', function (): void {
    $this->loeser->set($this->ich, 'task.assigned', [Channel::Email->value => Level::Digest]);
    $this->notifier->send(to: $this->ich, type: 'task.assigned', title: 'Probe');

    app(EmailDelivery::class)->vorschau($this->ich);

    Mail::assertNothingSent();
    // Haette die Vorschau den Zeitpunkt fortgeschrieben, waere die Meldung
    // beim naechsten echten Lauf verschwunden — ein Probelauf, der Post
    // verschluckt.
    expect(app(EmailDelivery::class)->sammeln($this->ich))->toBe(1);
});
