<?php

use Illuminate\Support\Facades\Log;
use Peppermint\Notifications\Push\PushForwarder;
use Peppermint\Notifications\Tests\Support\User;

/**
 * Das Abo wandert dorthin, wo der Schluessel liegt (#7993).
 *
 * Signieren kann nur, wer den privaten Teil des VAPID-Paares hat. Liegt der
 * in AI Brain, muss auch das Abo dort liegen — sonst haelt ein Produkt eine
 * Adresse, die es nicht bedienen kann.
 */
beforeEach(function (): void {
    $this->ich = User::create(['name' => 'Ich', 'email' => 'ich@beispiel.test']);
    $this->abo = [
        'endpoint' => 'https://push.beispiel.test/abc',
        'keys' => ['p256dh' => 'P', 'auth' => 'A'],
    ];
});

it('meldet im Einzelbetrieb gar nichts', function (): void {
    // Ein Produkt ohne Brain hat keinen Hub, an den es melden koennte — und
    // soll keine Verbindung nach draussen aufbauen.
    config()->set('notifications.hub', 'local');

    expect(app(PushForwarder::class)->subscribed($this->ich, $this->abo))->toBeFalse();
});

it('meldet nichts ohne Bruecke', function (): void {
    config()->set('notifications.hub', 'brain');

    // In dieser Testumgebung gibt es die Bruecken-Klasse nicht. Der Weg
    // muss das aushalten, statt mit „Class not found" abzubrechen — ein
    // Abo-Knopf, der eine Ausnahme wirft, ist schlimmer als einer, der den
    // Hub nicht erreicht.
    Log::spy();

    expect(app(PushForwarder::class)->subscribed($this->ich, $this->abo))->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $text): bool => str_contains($text, 'keine Bruecke'));
});

it('meldet nichts fuer einen Menschen ohne E-Mail', function (): void {
    // Die E-Mail ist der einzige produktuebergreifende Schluessel. Ohne sie
    // findet der Hub den Menschen nicht — und legte das Abo entweder
    // nirgends oder beim Falschen an.
    config()->set('notifications.hub', 'brain');

    $ohne = User::create(['name' => 'Ohne Adresse']);

    Log::spy();

    expect(app(PushForwarder::class)->subscribed($ohne, $this->abo))->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $text): bool => str_contains($text, 'ohne E-Mail'));
});
