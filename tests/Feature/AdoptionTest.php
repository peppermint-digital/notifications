<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Peppermint\Notifications\Models\Notification;
use Peppermint\Notifications\Tests\Support\User;
use Peppermint\Notifications\Tests\Support\Widget;

/**
 * Der eigentliche Zweck des Pakets: eine Tabelle uebernehmen, die es laengst
 * gibt — unter ihrem Namen und mit ihren Spaltennamen.
 *
 * Kein Produkt zieht um. AI Brain allein haelt ueber 17.000 Zeilen, der
 * Manager 5.019. Ein Paket, das einen Umzug verlangt, wird nicht eingebaut.
 */
beforeEach(function (): void {
    // Eine Tabelle, wie ein gewachsenes Produkt sie haette: anderer Name,
    // andere Spaltennamen, ein Feld mehr, das das Paket nicht kennt.
    Schema::create('meldungen', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('empfaenger_id');
        $table->string('art');
        $table->string('ueberschrift');
        $table->text('text')->nullable();
        $table->string('symbol')->nullable();
        $table->string('ziel')->nullable();
        $table->string('bezug_typ')->nullable();
        $table->unsignedBigInteger('bezug_id')->nullable();
        $table->unsignedBigInteger('ausgeloest_von')->nullable();
        $table->json('extra')->nullable();
        $table->boolean('gelesen')->default(false);
        $table->timestamp('gelesen_am')->nullable();
        // Eine Spalte, von der das Paket nie erfaehrt.
        $table->string('mandant')->nullable();
        $table->timestamps();
    });

    config()->set('notifications.tables.notifications', 'meldungen');
    config()->set('notifications.columns.notifications', [
        'user_id' => 'empfaenger_id',
        'type' => 'art',
        'title' => 'ueberschrift',
        'message' => 'text',
        'icon' => 'symbol',
        'link' => 'ziel',
        'notifiable_type' => 'bezug_typ',
        'notifiable_id' => 'bezug_id',
        'triggered_by' => 'ausgeloest_von',
        'data' => 'extra',
        'is_read' => 'gelesen',
        'read_at' => 'gelesen_am',
    ]);

    $this->empfaenger = User::create(['name' => 'Empfaenger']);
});

it('schreibt und liest ueber die fremden Spaltennamen', function (): void {
    $m = Notification::create([
        'empfaenger_id' => $this->empfaenger->id,
        'art' => 'task.assigned',
        'ueberschrift' => 'Aufgabe zugewiesen',
        'text' => 'Du wurdest zugewiesen.',
    ]);

    expect($m->getTable())->toBe('meldungen')
        ->and($m->field('title'))->toBe('Aufgabe zugewiesen')
        ->and($m->field('message'))->toBe('Du wurdest zugewiesen.')
        ->and($m->field('type'))->toBe('task.assigned');
});

it('filtert ueber die fremden Spaltennamen', function (): void {
    Notification::create([
        'empfaenger_id' => $this->empfaenger->id,
        'art' => 'mention',
        'ueberschrift' => 'Erwaehnt',
    ]);

    expect(Notification::forUser($this->empfaenger->id)->unread()->ofType('mention')->count())->toBe(1);
});

it('markiert ueber die fremden Spaltennamen als gelesen', function (): void {
    $m = Notification::create([
        'empfaenger_id' => $this->empfaenger->id,
        'art' => 'mention',
        'ueberschrift' => 'Erwaehnt',
    ]);

    $m->markAsRead();

    expect($m->fresh()->getAttribute('gelesen'))->toBeTrue()
        ->and($m->fresh()->getAttribute('gelesen_am'))->not->toBeNull()
        ->and($m->fresh()->isRead())->toBeTrue();
});

it('wandelt auch dann um, wenn die Spalte anders heisst', function (): void {
    // Eine Umwandlung, die auf den PAKETnamen geschluesselt waere, liefe hier
    // ins Leere: Die Spalte `is_read` gibt es nicht, also bliebe `gelesen` ein
    // roher Wert — bei MySQL eine 1, bei SQLite eine 1, und `=== true` waere
    // ueberall falsch.
    $m = Notification::create([
        'empfaenger_id' => $this->empfaenger->id,
        'art' => 'mention',
        'ueberschrift' => 'Erwaehnt',
        'extra' => ['anzahl' => 7],
        'gelesen' => 1,
    ]);

    $frisch = $m->fresh();

    expect($frisch->getAttribute('gelesen'))->toBeTrue()
        ->and($frisch->getAttribute('gelesen'))->not->toBe(1)
        ->and($frisch->field('data'))->toBe(['anzahl' => 7]);
});

it('findet den Gegenstand ueber die fremden Spaltennamen', function (): void {
    $widget = Widget::create(['title' => 'Unterhaltung']);

    Notification::create([
        'empfaenger_id' => $this->empfaenger->id,
        'art' => 'chat.message',
        'ueberschrift' => 'Neue Nachricht',
        'bezug_typ' => Widget::class,
        'bezug_id' => $widget->id,
    ]);

    $treffer = Notification::forUser($this->empfaenger->id)
        ->ofType('chat.message')
        ->unread()
        ->about(Widget::class, $widget->id)
        ->first();

    expect($treffer)->not->toBeNull()
        ->and($treffer->notifiable)->toBeInstanceOf(Widget::class);
});

it('laesst eine Spalte unberuehrt, von der das Paket nichts weiss', function (): void {
    // Der Fehler, gegen den das steht: Ein Dienst, der seine Felder aufzaehlt,
    // schreibt beim Speichern alles andere auf null zurueck. Das Werkzeug
    // meldet Erfolg, die Spalte ist leer, und niemand sieht es.
    $m = Notification::create([
        'empfaenger_id' => $this->empfaenger->id,
        'art' => 'mention',
        'ueberschrift' => 'Erwaehnt',
        'mandant' => 'crewtex',
    ]);

    $m->markAsRead();

    expect($m->fresh()->getAttribute('mandant'))->toBe('crewtex');
});

it('legt KEINE eigene Tabelle an, wenn das Produkt schon eine hat', function (): void {
    // Die Vorgabe `run_migrations = false` ist der Grund, warum das Paket in
    // ein laufendes Produkt eingebaut werden kann, ohne dass etwas passiert.
    expect(config('notifications.run_migrations'))->toBeTrue(); // im Test eingeschaltet

    // Entscheidend ist die Migration selbst: Sie steigt aus, wenn die Tabelle
    // dasteht — sonst braeche ein Produkt beim ersten `migrate` ab.
    config()->set('notifications.tables.notifications', 'meldungen');

    $vorher = count(Schema::getColumnListing('meldungen'));

    $migration = require __DIR__.'/../../database/migrations/2026_10_16_120000_create_notifications_table.php';
    $migration->up();

    expect(Schema::getColumnListing('meldungen'))->toHaveCount($vorher);
});
