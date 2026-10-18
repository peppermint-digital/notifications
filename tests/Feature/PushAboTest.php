<?php

use Illuminate\Support\Facades\Route;
use Peppermint\Notifications\Push\PushForwarder;
use Peppermint\Notifications\Tests\Support\User;

/**
 * Das Abo entgegennehmen und weiterreichen (#8164).
 *
 * Hier wird NICHTS gespeichert: Signieren kann nur, wer den privaten
 * VAPID-Schluessel haelt, und das ist AI Brain. Ein Produkt mit einer
 * Abo-Adresse, die es nie bedienen kann, haette eine Tabelle, die aussieht
 * wie eine laufende Zustellung und keine ist.
 */
beforeEach(function (): void {
    Route::middleware(['web', 'auth'])->group(__DIR__.'/../../routes/push.php');

    $this->ich = User::create(['name' => 'Ich', 'email' => 'ich@beispiel.test']);

    $this->abo = [
        'endpoint' => 'https://push.beispiel.test/abc',
        'keys' => ['p256dh' => 'P256', 'auth' => 'AUTH'],
    ];
});

it('reicht ein Abo an den Hub weiter', function (): void {
    $this->mock(PushForwarder::class)
        ->shouldReceive('subscribed')->once()->andReturn(true);

    $this->actingAs($this->ich)
        ->postJson('/api/push/subscribe', $this->abo)
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('meldet 503 statt Erfolg, wenn der Hub nicht erreichbar war', function (): void {
    // Hier gibt es keine eigene Zeile, die das Abo noch kennen wuerde. Ein
    // „gespeichert" ohne Speicherort waere gelogen — und die Oberflaeche
    // setzte einen Haken, hinter dem nichts ist.
    $this->mock(PushForwarder::class)
        ->shouldReceive('subscribed')->once()->andReturn(false);

    $this->actingAs($this->ich)
        ->postJson('/api/push/subscribe', $this->abo)
        ->assertStatus(503)
        ->assertJsonPath('error', 'hub_unreachable');
});

it('weist ein Abo ohne Schluessel ab', function (): void {
    $this->actingAs($this->ich)
        ->postJson('/api/push/subscribe', ['endpoint' => 'https://push.beispiel.test/abc'])
        ->assertStatus(422);
});

it('meldet das Abbestellen immer als erledigt', function (): void {
    // Der Browser hat sein Abo in jedem Fall schon weggeworfen, bevor er
    // hier anklopft. Ein Fehler liesse die Oberflaeche „abonniert" zeigen,
    // obwohl nichts mehr ankommt — die schlechtere von zwei Unwahrheiten.
    $this->mock(PushForwarder::class)
        ->shouldReceive('unsubscribed')->once()->andReturn(false);

    $this->actingAs($this->ich)
        ->deleteJson('/api/push/subscribe', ['endpoint' => $this->abo['endpoint']])
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('verlangt eine Anmeldung', function (): void {
    $this->postJson('/api/push/subscribe', $this->abo)->assertStatus(401);
});
