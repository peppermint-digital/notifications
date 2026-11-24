<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Peppermint\Notifications\Console\PushSchluesselCommand;
use Peppermint\Notifications\NotificationsServiceProvider;
use Peppermint\Notifications\Push\PushBetrieb;
use Peppermint\Notifications\Push\PushForwarder;
use Peppermint\Notifications\Push\PushKeys;
use Peppermint\Notifications\Push\PushSubscription;
use Peppermint\Notifications\Tests\Support\User;

/**
 * Der eigene Push-Betrieb (v0.32): Abos in der eigenen Tabelle, eigener
 * Schluessel, nichts geht an den Hub.
 */
function lokalerPushTabelle(): void
{
    $migration = require __DIR__.'/../../database/push-migrations/2026_11_24_120000_create_push_subscriptions_table.php';
    $migration->up();
}

beforeEach(function (): void {
    Route::middleware(['web', 'auth'])->group(__DIR__.'/../../routes/push.php');

    $this->ich = User::create(['name' => 'Ich', 'email' => 'ich@beispiel.test']);
    $this->du = User::create(['name' => 'Du', 'email' => 'du@beispiel.test']);

    $this->abo = [
        'endpoint' => 'https://push.beispiel.test/'.str_repeat('a', 600),
        'keys' => ['p256dh' => 'P256', 'auth' => 'AUTH'],
    ];
});

describe('der Modus', function (): void {
    it('folgt ohne Angabe dem Betriebsmodus wie bis v0.31', function (): void {
        config()->set('notifications.push.driver', null);

        config()->set('notifications.hub', 'brain');
        expect(PushBetrieb::modus())->toBe('hub');

        // AI Brain steht auf `local` und hat seine eigene Abo-Tabelle: Ein
        // Anheben des Pakets darf dort nicht anfangen, Abos zu speichern.
        config()->set('notifications.hub', 'local');
        expect(PushBetrieb::modus())->toBe('none');
    });

    it('nimmt den ausdruecklich gesetzten Weg', function (): void {
        config()->set('notifications.hub', 'brain');
        config()->set('notifications.push.driver', 'local');

        expect(PushBetrieb::modus())->toBe('local');
    });

    it('faellt bei einem unbekannten Wert auf den Betriebsmodus zurueck', function (): void {
        config()->set('notifications.hub', 'local');
        config()->set('notifications.push.driver', 'irgendwas');

        expect(PushBetrieb::modus())->toBe('none');
    });
});

describe('im Betrieb local', function (): void {
    beforeEach(function (): void {
        config()->set('notifications.hub', 'local');
        config()->set('notifications.push.driver', 'local');
        config()->set('notifications.push.public_key', 'EIGENER-SCHLUESSEL');
        config()->set('notifications.push.hub_url', 'https://hub.beispiel.test/api/push/public-key');
        lokalerPushTabelle();
    });

    it('speichert ein Abo in der eigenen Tabelle und meldet nichts an den Hub', function (): void {
        $this->mock(PushForwarder::class)->shouldNotReceive('subscribed');

        $this->actingAs($this->ich)
            ->postJson('/api/push/subscribe', $this->abo, ['User-Agent' => 'Testbrowser'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $zeile = PushSubscription::query()->sole();

        expect($zeile->user_id)->toBe($this->ich->id)
            ->and($zeile->endpoint)->toBe($this->abo['endpoint'])
            ->and($zeile->endpoint_hash)->toBe(hash('sha256', $this->abo['endpoint']))
            ->and($zeile->public_key)->toBe('P256')
            ->and($zeile->auth_token)->toBe('AUTH')
            ->and($zeile->user_agent)->toBe('Testbrowser');
    });

    it('legt dasselbe Geraet nicht zweimal an', function (): void {
        $this->actingAs($this->ich)->postJson('/api/push/subscribe', $this->abo)->assertOk();
        $this->actingAs($this->ich)->postJson('/api/push/subscribe', $this->abo)->assertOk();

        expect(PushSubscription::query()->count())->toBe(1);
    });

    it('gibt das Geraet an die Person, die zuletzt darauf abonniert hat', function (): void {
        $this->actingAs($this->ich)->postJson('/api/push/subscribe', $this->abo)->assertOk();
        $this->actingAs($this->du)->postJson('/api/push/subscribe', $this->abo)->assertOk();

        expect(PushSubscription::query()->sole()->user_id)->toBe($this->du->id);
    });

    it('nimmt das eigene Abo zurueck, ein fremdes nicht', function (): void {
        $this->actingAs($this->ich)->postJson('/api/push/subscribe', $this->abo)->assertOk();

        $this->actingAs($this->du)
            ->deleteJson('/api/push/subscribe', ['endpoint' => $this->abo['endpoint']])
            ->assertOk();

        expect(PushSubscription::query()->count())->toBe(1);

        $this->actingAs($this->ich)
            ->deleteJson('/api/push/subscribe', ['endpoint' => $this->abo['endpoint']])
            ->assertOk();

        expect(PushSubscription::query()->count())->toBe(0);
    });

    it('nennt den eigenen Schluessel und fragt den Hub nicht', function (): void {
        Http::fake();

        expect(app(PushKeys::class)->publicKey())->toBe('EIGENER-SCHLUESSEL');

        Http::assertNothingSent();
    });
});

describe('ohne Push', function (): void {
    beforeEach(function (): void {
        config()->set('notifications.hub', 'local');
        config()->set('notifications.push.driver', null);
    });

    it('nimmt kein Abo an, das niemand bedient', function (): void {
        $this->mock(PushForwarder::class)->shouldNotReceive('subscribed');

        $this->actingAs($this->ich)
            ->postJson('/api/push/subscribe', $this->abo)
            ->assertStatus(503)
            ->assertJsonPath('error', 'push_disabled');
    });

    it('laedt die Abo-Migration nur im Betrieb local', function (): void {
        $pfad = realpath(__DIR__.'/../../database/push-migrations');
        $geladen = fn (): bool => in_array($pfad, array_map('realpath', app('migrator')->paths()), true);

        (new NotificationsServiceProvider(app()))->boot();
        expect($geladen())->toBeFalse();

        config()->set('notifications.push.driver', 'local');
        (new NotificationsServiceProvider(app()))->boot();
        expect($geladen())->toBeTrue();
    });
});

describe('die Migration', function (): void {
    it('laesst eine vorhandene Abo-Tabelle unberuehrt', function (): void {
        Schema::create('push_subscriptions', function ($table): void {
            $table->id();
            $table->string('gewachsen');
        });

        lokalerPushTabelle();

        expect(Schema::hasColumn('push_subscriptions', 'gewachsen'))->toBeTrue()
            ->and(Schema::hasColumn('push_subscriptions', 'endpoint_hash'))->toBeFalse();
    });
});

describe('der Schluessel-Befehl', function (): void {
    beforeEach(function (): void {
        $this->env = sys_get_temp_dir().'/notifications-env-'.bin2hex(random_bytes(4));
        file_put_contents($this->env, "APP_NAME=Test\n");
        app()->useEnvironmentPath(dirname($this->env));
        app()->loadEnvironmentFrom(basename($this->env));
    });

    afterEach(function (): void {
        @unlink($this->env);
    });

    it('erzeugt ein gueltiges P-256-Paar', function (): void {
        [$oeffentlich, $privat] = PushSchluesselCommand::erzeugen();

        $roh = static fn (string $b64) => base64_decode(strtr($b64, '-_', '+/'));

        expect(strlen($roh($oeffentlich)))->toBe(65)
            ->and($roh($oeffentlich)[0])->toBe("\x04")
            ->and(strlen($roh($privat)))->toBe(32)
            ->and($oeffentlich)->not->toContain('=');
    });

    it('schreibt das Paar in die .env und zeigt es nicht an', function (): void {
        Artisan::call('notifications:push-schluessel');
        $ausgabe = Artisan::output();

        $inhalt = file_get_contents($this->env);

        preg_match('/^VAPID_PRIVATE_KEY=(\S+)$/m', $inhalt, $privat);
        preg_match('/^VAPID_PUBLIC_KEY=(\S+)$/m', $inhalt, $oeffentlich);

        expect($privat[1] ?? null)->not->toBeNull()
            ->and($oeffentlich[1] ?? null)->not->toBeNull()
            ->and($ausgabe)->not->toContain($privat[1])
            ->and($inhalt)->toStartWith("APP_NAME=Test\n");
    });

    it('ersetzt ein vorhandenes Paar nicht ohne --force', function (): void {
        file_put_contents($this->env, "VAPID_PUBLIC_KEY=ALT\nVAPID_PRIVATE_KEY=ALT\n");

        expect(Artisan::call('notifications:push-schluessel'))->toBe(1)
            ->and(file_get_contents($this->env))->toBe("VAPID_PUBLIC_KEY=ALT\nVAPID_PRIVATE_KEY=ALT\n");

        expect(Artisan::call('notifications:push-schluessel', ['--force' => true]))->toBe(0)
            ->and(file_get_contents($this->env))->not->toContain('=ALT');
    });
});
