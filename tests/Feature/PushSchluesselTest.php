<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Peppermint\Notifications\Push\PushKeys;
use Peppermint\Notifications\Tests\Support\User;

/**
 * Ein Schluessel fuer alle Systeme (#7993).
 *
 * Ein Web-Push-Abo haengt am VAPID-PUBLIC-KEY, mit dem der Browser
 * abonniert hat — nicht an der Domain. Holen alle Produkte denselben
 * Schluessel, kann nur die Stelle signieren, die den privaten Teil haelt.
 */
beforeEach(function (): void {
    Cache::flush();
    app(PushKeys::class)->vergessen();

    config()->set('notifications.push.public_key', 'EIGENER-SCHLUESSEL');
    config()->set('notifications.push.hub_url', 'https://brain.test/api/push/public-key');
});

it('nimmt im Einzelbetrieb den eigenen Schluessel', function (): void {
    config()->set('notifications.hub', 'local');
    Http::fake();

    expect(app(PushKeys::class)->publicKey())->toBe('EIGENER-SCHLUESSEL');

    // Und fragt gar nicht erst beim Hub nach — ein Produkt ohne Brain soll
    // keine Verbindung nach draussen aufbauen, auch keine, die nichts tut.
    Http::assertNothingSent();
});

it('holt ihn im Hub-Betrieb vom Brain', function (): void {
    config()->set('notifications.hub', 'brain');
    Http::fake(['brain.test/*' => Http::response(['public_key' => 'HUB-SCHLUESSEL'])]);

    expect(app(PushKeys::class)->publicKey())->toBe('HUB-SCHLUESSEL');
});

it('fragt nicht bei jedem Aufruf nach', function (): void {
    config()->set('notifications.hub', 'brain');
    Http::fake(['brain.test/*' => Http::response(['public_key' => 'HUB-SCHLUESSEL'])]);

    app(PushKeys::class)->publicKey();
    app(PushKeys::class)->publicKey();

    // Der Schluessel aendert sich praktisch nie. Ihn an jeder Seite neu zu
    // holen machte jede davon von der Antwortzeit des Hubs abhaengig.
    Http::assertSentCount(1);
});

it('faellt auf den eigenen zurueck, wenn der Hub schweigt', function (): void {
    // Weniger als gewollt, aber nicht nichts: Wer damit abonniert, bekommt
    // seine Meldungen vom Produkt statt vom Brain.
    config()->set('notifications.hub', 'brain');
    Http::fake(['brain.test/*' => Http::response([], 503)]);

    expect(app(PushKeys::class)->publicKey())->toBe('EIGENER-SCHLUESSEL');
});

it('liefert null, wenn es nirgends einen gibt', function (): void {
    // Dann darf die Oberflaeche gar nicht erst anbieten zu abonnieren. Ein
    // Knopf, der nichts tut, ist schlimmer als keiner.
    config()->set('notifications.hub', 'brain');
    config()->set('notifications.push.public_key', null);
    Http::fake(['brain.test/*' => Http::response([], 503)]);

    expect(app(PushKeys::class)->publicKey())->toBeNull();
});

it('sagt es, wenn der Hub-Betrieb ohne Adresse eingestellt ist', function (): void {
    config()->set('notifications.hub', 'brain');
    config()->set('notifications.push.hub_url', null);
    Http::fake();

    expect(app(PushKeys::class)->publicKey())->toBe('EIGENER-SCHLUESSEL');
    Http::assertNothingSent();
});

/* ------------------------------------------------------- der Endpunkt */

it('gibt den Schluessel in beiden Schreibweisen heraus', function (): void {
    // Die vier Produkte haben ihre Endpunkte mit je eigener Schreibweise
    // gewachsen. Ein Frontend, das die falsche liest, bekommt `undefined`
    // und bietet stumm kein Abo an.
    Route::middleware('web')->group(__DIR__.'/../../routes/push.php');

    config()->set('notifications.hub', 'local');

    $this->actingAs(User::create(['name' => 'Ich']))
        ->getJson('/api/push/public-key')
        ->assertOk()
        ->assertJson(['public_key' => 'EIGENER-SCHLUESSEL', 'publicKey' => 'EIGENER-SCHLUESSEL']);
});
