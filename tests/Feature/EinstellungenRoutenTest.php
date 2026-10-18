<?php

use Illuminate\Support\Facades\Route;
use Peppermint\Notifications\Preferences\NotificationPreference;
use Peppermint\Notifications\Tests\Support\User;
use Peppermint\Notifications\Types\TypeRegistry;

beforeEach(function (): void {
    Route::middleware('web')
        ->prefix('api/notification-preferences')
        ->name('notification-preferences.')
        ->group(__DIR__.'/../../routes/preferences.php');

    app(TypeRegistry::class)->registerMany([
        ['key' => 'task.assigned', 'label' => 'Aufgabe zugewiesen', 'icon' => 'user-plus', 'color' => 'blue'],
    ]);

    $this->ich = User::create(['name' => 'Ich']);
});

it('liefert die Matrix mitsamt Woertern fuer Wege und Stufen', function (): void {
    // Die Woerter kommen MIT: Stuenden sie im Frontend, waeren sie beim
    // naechsten Mal nur dort ergaenzt — und die Maske boete eine Stufe an,
    // die der Server nicht kennt.
    $this->actingAs($this->ich)->getJson('/api/notification-preferences')
        ->assertOk()
        ->assertJsonPath('types.0.type', 'task.assigned')
        ->assertJsonPath('types.0.channels.email.level', 'off')
        ->assertJsonCount(3, 'channels')
        ->assertJsonCount(3, 'levels');
});

it('speichert eine Zeile der Matrix', function (): void {
    $this->actingAs($this->ich)
        ->postJson('/api/notification-preferences', [
            'type' => 'task.assigned',
            'channels' => ['push' => 'off', 'email' => 'instant', 'inapp' => null],
        ])
        ->assertOk()
        ->assertJsonPath('types.0.channels.push.level', 'off')
        ->assertJsonPath('types.0.channels.push.eigen', true)
        // Nicht gesetzt heisst „folgt der Vorgabe", nicht „auf den heutigen
        // Vorgabewert gestellt".
        ->assertJsonPath('types.0.channels.inapp.eigen', false);
});

it('weist eine unbekannte Stufe ab', function (): void {
    $this->actingAs($this->ich)
        ->postJson('/api/notification-preferences', [
            'type' => 'task.assigned',
            'channels' => ['push' => 'vielleicht'],
        ])
        ->assertStatus(422);

    expect(NotificationPreference::query()->count())->toBe(0);
});

it('speichert nur fuer den angemeldeten Menschen', function (): void {
    $andere = User::create(['name' => 'Andere']);

    $this->actingAs($this->ich)->postJson('/api/notification-preferences', [
        'type' => 'task.assigned',
        'channels' => ['push' => 'off'],
    ]);

    // Keine Kennung im Rumpf, also auch keine Moeglichkeit, eine fremde zu
    // schicken — festgehalten, damit das so bleibt.
    expect(NotificationPreference::query()->count())->toBe(1)
        ->and(NotificationPreference::query()->first()->user_id)->toBe($this->ich->id)
        ->and(NotificationPreference::query()->forUser($andere)->count())->toBe(0);
});
