<?php

use Illuminate\Support\Facades\Route;
use Peppermint\Notifications\Push\PushForwarder;
use Peppermint\Notifications\Push\PushSubscription;
use Peppermint\Notifications\Tests\Support\User;

/**
 * Kennt der Server das Abo dieses Geraets? (pm #10206)
 *
 * Der Browser allein weiss nur, dass ER ein Abo haelt. Ob es noch ankommt,
 * weiss der Server: Antwortet der Push-Dienst beim Versand mit 404 oder 410,
 * loescht {@see \Peppermint\Notifications\Push\PushZusteller} die Zeile. Bis
 * v0.32 meldete die Oberflaeche ein solches Abo stillschweigend neu an und
 * zeigte „abonniert" — fuer einen Endpunkt, den der Dienst laengst verworfen
 * hatte.
 */
function statusAboTabelle(): void
{
    $migration = require __DIR__.'/../../database/push-migrations/2026_11_24_120000_create_push_subscriptions_table.php';
    $migration->up();
}

beforeEach(function (): void {
    Route::middleware(['web', 'auth'])->group(__DIR__.'/../../routes/push.php');

    $this->ich = User::create(['name' => 'Ich', 'email' => 'ich@beispiel.test']);
    $this->du = User::create(['name' => 'Du', 'email' => 'du@beispiel.test']);

    $this->endpoint = 'https://push.beispiel.test/'.str_repeat('b', 600);
});

describe('im Betrieb local', function (): void {
    beforeEach(function (): void {
        config()->set('notifications.hub', 'local');
        config()->set('notifications.push.driver', 'local');
        statusAboTabelle();
    });

    it('kennt das eigene Abo', function (): void {
        $this->actingAs($this->ich)->postJson('/api/push/subscribe', [
            'endpoint' => $this->endpoint,
            'keys' => ['p256dh' => 'P256', 'auth' => 'AUTH'],
        ])->assertOk();

        $this->actingAs($this->ich)
            ->postJson('/api/push/subscribe/status', ['endpoint' => $this->endpoint])
            ->assertOk()
            ->assertExactJson(['bekannt' => true]);
    });

    it('kennt ein geloeschtes Abo nicht mehr', function (): void {
        $this->actingAs($this->ich)
            ->postJson('/api/push/subscribe/status', ['endpoint' => $this->endpoint])
            ->assertOk()
            ->assertExactJson(['bekannt' => false]);
    });

    it('zaehlt das Abo einer anderen Person auf demselben Geraet nicht als eigenes', function (): void {
        $this->actingAs($this->du)->postJson('/api/push/subscribe', [
            'endpoint' => $this->endpoint,
            'keys' => ['p256dh' => 'P256', 'auth' => 'AUTH'],
        ])->assertOk();

        $this->actingAs($this->ich)
            ->postJson('/api/push/subscribe/status', ['endpoint' => $this->endpoint])
            ->assertOk()
            ->assertExactJson(['bekannt' => false]);
    });

    it('legt beim Nachsehen keine Zeile an', function (): void {
        $this->actingAs($this->ich)
            ->postJson('/api/push/subscribe/status', ['endpoint' => $this->endpoint])
            ->assertOk();

        expect(PushSubscription::query()->count())->toBe(0);
    });

    it('verlangt einen Endpunkt', function (): void {
        $this->actingAs($this->ich)
            ->postJson('/api/push/subscribe/status', [])
            ->assertStatus(422);
    });
});

it('weiss es im Hub-Betrieb nicht und fragt den Hub nicht', function (): void {
    config()->set('notifications.hub', 'brain');
    $this->mock(PushForwarder::class)->shouldNotReceive('subscribed');

    $this->actingAs($this->ich)
        ->postJson('/api/push/subscribe/status', ['endpoint' => $this->endpoint])
        ->assertOk()
        ->assertExactJson(['bekannt' => null]);
});

it('weiss es ohne Push nicht', function (): void {
    config()->set('notifications.hub', 'local');
    config()->set('notifications.push.driver', 'none');

    $this->actingAs($this->ich)
        ->postJson('/api/push/subscribe/status', ['endpoint' => $this->endpoint])
        ->assertOk()
        ->assertExactJson(['bekannt' => null]);
});

it('verlangt eine Anmeldung', function (): void {
    $this->postJson('/api/push/subscribe/status', ['endpoint' => $this->endpoint])->assertStatus(401);
});
