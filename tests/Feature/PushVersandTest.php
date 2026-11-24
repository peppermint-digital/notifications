<?php

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Queue;
use Peppermint\Notifications\Console\PushSchluesselCommand;
use Peppermint\Notifications\Delivery\Notifier;
use Peppermint\Notifications\NotificationsServiceProvider;
use Peppermint\Notifications\Preferences\Channel;
use Peppermint\Notifications\Preferences\Level;
use Peppermint\Notifications\Preferences\PreferenceResolver;
use Peppermint\Notifications\Push\PushNutzlast;
use Peppermint\Notifications\Push\PushSubscription;
use Peppermint\Notifications\Push\PushZusteller;
use Peppermint\Notifications\Push\SendePush;
use Peppermint\Notifications\Tests\Support\User;

/**
 * Der eigene Push-Versand (v0.32): Meldung entsteht -> Warteschlange ->
 * signierter Web-Push an jedes Geraet der Person.
 *
 * Der Push-Dienst ist hier ein Guzzle-MockHandler. Verschluesselt und
 * signiert wird echt — mit einem echten VAPID-Paar und echten
 * Geraeteschluesseln —, nur die Antwort des Dienstes ist gestellt.
 */
function geraeteSchluessel(): array
{
    $paar = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $ec = openssl_pkey_get_details($paar)['ec'];
    $b64 = static fn (string $b): string => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');

    return [
        'p256dh' => $b64("\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)),
        'auth' => $b64(random_bytes(16)),
    ];
}

function pushDienst(array $antworten, array &$verlauf): void
{
    $stapel = HandlerStack::create(new MockHandler($antworten));
    $stapel->push(Middleware::history($verlauf));

    app()->instance(PushZusteller::class, new PushZusteller(['handler' => $stapel]));
}

function abo(User $person, string $endpoint): PushSubscription
{
    $schluessel = geraeteSchluessel();

    return PushSubscription::query()->create([
        'user_id' => $person->id,
        'endpoint' => $endpoint,
        'endpoint_hash' => PushSubscription::fingerabdruck($endpoint),
        'public_key' => $schluessel['p256dh'],
        'auth_token' => $schluessel['auth'],
    ]);
}

beforeEach(function (): void {
    [$oeffentlich, $privat] = PushSchluesselCommand::erzeugen();

    config()->set('notifications.hub', 'local');
    config()->set('notifications.push.driver', 'local');
    config()->set('notifications.push.public_key', $oeffentlich);
    config()->set('notifications.push.private_key', $privat);
    config()->set('notifications.push.subject', 'mailto:push@beispiel.test');
    config()->set('app.url', 'https://pm.beispiel.test');

    // Der Horcher haengt sich beim Hochfahren ein — mit dem Betrieb, der
    // DANN gilt. Deshalb hier noch einmal hochfahren, jetzt mit `local`.
    (new NotificationsServiceProvider(app()))->boot();
    $migration = require __DIR__.'/../../database/push-migrations/2026_11_24_120000_create_push_subscriptions_table.php';
    $migration->up();

    $this->oeffentlich = $oeffentlich;
    $this->ich = User::create(['name' => 'Ich', 'email' => 'ich@beispiel.test']);
    $this->du = User::create(['name' => 'Du', 'email' => 'du@beispiel.test']);
});

it('reiht fuer eine neue Meldung einen Push ein', function (): void {
    Queue::fake();

    $meldung = app(Notifier::class)->send(to: $this->ich, type: 'task.assigned', title: 'Aufgabe');

    Queue::assertPushed(SendePush::class, fn (SendePush $job): bool => $job->notificationId === $meldung->getKey());
});

it('pusht nicht, wenn die Person Push fuer diese Art abgeschaltet hat', function (): void {
    Queue::fake();
    app(PreferenceResolver::class)->set($this->ich, 'task.assigned', [Channel::Push->value => Level::Off]);

    app(Notifier::class)->send(to: $this->ich, type: 'task.assigned', title: 'Aufgabe');

    Queue::assertNotPushed(SendePush::class);
});

it('pusht eine fortgeschriebene Sammelmeldung nicht ein zweites Mal', function (): void {
    Queue::fake();
    $notifier = app(Notifier::class);

    $notifier->rolling(to: $this->ich, type: 'chat.message', title: 'Chat', message: fn (int $n): string => "{$n} neue");
    $notifier->rolling(to: $this->ich, type: 'chat.message', title: 'Chat', message: fn (int $n): string => "{$n} neue");

    Queue::assertPushed(SendePush::class, 1);
});

it('pusht nichts ohne eigenes VAPID-Paar', function (): void {
    Queue::fake();
    config()->set('notifications.push.private_key', null);

    app(Notifier::class)->send(to: $this->ich, type: 'task.assigned', title: 'Aufgabe');

    Queue::assertNotPushed(SendePush::class);
});

it('stellt signiert und verschluesselt an jedes Geraet der Person zu, nicht an fremde', function (): void {
    abo($this->ich, 'https://push.beispiel.test/telefon');
    abo($this->ich, 'https://push.beispiel.test/laptop');
    abo($this->du, 'https://push.beispiel.test/fremd');

    $verlauf = [];
    pushDienst([new Response(201), new Response(201)], $verlauf);
    Queue::fake();

    $meldung = app(Notifier::class)->send(to: $this->ich, type: 'task.assigned', title: 'Aufgabe', link: '/tasks/7');
    (new SendePush($meldung->getKey()))->handle(app(PushZusteller::class));

    $ziele = collect($verlauf)->map(fn (array $eintrag): string => (string) $eintrag['request']->getUri())->sort()->values()->all();

    expect($ziele)->toBe(['https://push.beispiel.test/laptop', 'https://push.beispiel.test/telefon']);

    $anfrage = $verlauf[0]['request'];

    expect($anfrage->getHeaderLine('Content-Encoding'))->toBe('aes128gcm')
        ->and($anfrage->getHeaderLine('Authorization'))->toContain('vapid t=')
        ->and($anfrage->getHeaderLine('Authorization'))->toContain('k='.$this->oeffentlich)
        ->and($anfrage->getHeaderLine('TTL'))->toBe('86400')
        ->and(strlen((string) $anfrage->getBody()))->toBeGreaterThan(0)
        ->and((string) $anfrage->getBody())->not->toContain('Aufgabe');

    expect(PushSubscription::query()->whereNotNull('last_used_at')->count())->toBe(2);
});

it('loescht ein abgelaufenes Abo und behaelt eines, dessen Dienst nur gerade scheitert', function (): void {
    abo($this->ich, 'https://push.beispiel.test/abgelaufen');
    abo($this->ich, 'https://push.beispiel.test/wackelt');

    $verlauf = [];
    // Reihenfolge der Antworten = Reihenfolge der Abos (nach id).
    pushDienst([new Response(410), new Response(500)], $verlauf);
    Queue::fake();

    $meldung = app(Notifier::class)->send(to: $this->ich, type: 'task.assigned', title: 'Aufgabe');
    (new SendePush($meldung->getKey()))->handle(app(PushZusteller::class));

    expect(PushSubscription::query()->pluck('endpoint')->all())->toBe(['https://push.beispiel.test/wackelt']);
});

it('baut die Nutzlast fuer den Service Worker mit absolutem Verweis', function (): void {
    $meldung = app(Notifier::class)->send(to: $this->ich, type: 'task.assigned', title: 'Aufgabe', message: 'Bitte ansehen', link: '/tasks/7');

    expect(PushNutzlast::aus($meldung))->toMatchArray([
        'title' => 'Aufgabe',
        'body' => 'Bitte ansehen',
        'url' => 'https://pm.beispiel.test/tasks/7',
        'tag' => 'notification-'.$meldung->getKey(),
    ])->and(PushNutzlast::aus($meldung)['data']['url'])->toBe('https://pm.beispiel.test/tasks/7');
});
