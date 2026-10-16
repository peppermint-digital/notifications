<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Peppermint\Notifications\Delivery\Notifier;
use Peppermint\Notifications\Feed\NotificationActions;
use Peppermint\Notifications\Feed\NotificationFeed;
use Peppermint\Notifications\Models\Notification;
use Peppermint\Notifications\Tests\Support\User;

/**
 * Die Laravel-Standardtabelle — drei der vier Produkte stehen darauf.
 *
 * Sie unterscheidet sich in drei Punkten von der Zielform, und jeder davon
 * hat eine eigene Falle:
 *
 * 1. Der Schluessel ist ein UUID. Ohne `key_type` castet Eloquent ihn zu
 *    `int`: aus `9b1d…` wird `9`, und zwei Meldungen teilen sich eine
 *    Kennung.
 * 2. `notifiable_*` ist der EMPFAENGER, nicht der Gegenstand.
 * 3. `type` ist die Notification-KLASSE, nicht die fachliche Art.
 *
 * Punkt 2 und 3 loest die Spalten-Abbildung; Punkt 1 braucht die Angabe.
 */
beforeEach(function (): void {
    Schema::create('standard_notifications', function (Blueprint $table): void {
        // Wie Laravel sie anlegt …
        $table->uuid('id')->primary();
        $table->string('type');
        $table->string('notifiable_type');
        $table->unsignedBigInteger('notifiable_id');
        $table->text('data');
        $table->timestamp('read_at')->nullable();
        $table->timestamps();

        // … und was additiv dazukommt.
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('kind')->nullable();
        $table->string('title')->nullable();
        $table->text('message')->nullable();
        $table->string('icon')->nullable();
        $table->string('link')->nullable();
        $table->string('subject_type')->nullable();
        $table->unsignedBigInteger('subject_id')->nullable();
        $table->unsignedBigInteger('triggered_by')->nullable();
        $table->boolean('is_read')->default(false);
    });

    config()->set('notifications.tables.notifications', 'standard_notifications');
    config()->set('notifications.key_type', 'string');
    config()->set('notifications.columns.notifications', [
        'type' => 'kind',
        'notifiable_type' => 'subject_type',
        'notifiable_id' => 'subject_id',
    ]);

    $this->ich = User::create(['name' => 'Ich']);
});

it('behaelt einen UUID-Schluessel, statt ihn zu einer Zahl zu machen', function (): void {
    $uuid = (string) Str::uuid();

    $m = Notification::create([
        'id' => $uuid,
        'type' => 'App\\Notifications\\AppNotification',
        'notifiable_type' => 'user',
        'notifiable_id' => $this->ich->id,
        'data' => '{}',
        'user_id' => $this->ich->id,
        'kind' => 'infra.server',
        'title' => 'Server nicht erreichbar',
    ]);

    expect($m->getKey())->toBe($uuid)
        ->and($m->getKey())->toBeString()
        // Die Probe aufs Exempel: Mit `int` als Schluesseltyp waere hier
        // die fuehrende Ziffer uebrig.
        ->and(Notification::query()->whereKey($uuid)->first()?->field('title'))->toBe('Server nicht erreichbar');
});

it('zaehlt bei UUIDs nicht hoch', function (): void {
    expect((new Notification)->getIncrementing())->toBeFalse();
});

it('liest die Art aus der umgebogenen Spalte, nicht aus der Notification-Klasse', function (): void {
    Notification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\AppNotification',
        'notifiable_type' => 'user',
        'notifiable_id' => $this->ich->id,
        'data' => '{}',
        'user_id' => $this->ich->id,
        'kind' => 'infra.server',
        'title' => 'Server nicht erreichbar',
    ]);

    $treffer = Notification::query()->forUser($this->ich->id)->ofType('infra.server')->first();

    expect($treffer)->not->toBeNull()
        ->and($treffer->field('type'))->toBe('infra.server');
});

it('haelt Empfaenger und Gegenstand auseinander', function (): void {
    // Der Fehler, den die Umbiegung verhindert: In der Standardform ist
    // `notifiable` der Empfaenger. Wer das Paket darauf zeigen liesse, haette
    // als Gegenstand jeder Meldung den Menschen, der sie bekommt — und die
    // Buendelung fasste alles einer Person zu einer Zeile zusammen.
    Notification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\AppNotification',
        'notifiable_type' => 'user',
        'notifiable_id' => $this->ich->id,
        'data' => '{}',
        'user_id' => $this->ich->id,
        'kind' => 'infra.server',
        'title' => 'Server 5 nicht erreichbar',
        'subject_type' => 'server',
        'subject_id' => 5,
    ]);

    $m = Notification::query()->forUser($this->ich->id)->first();

    expect($m->field('user_id'))->toBe($this->ich->id)
        ->and($m->field('notifiable_type'))->toBe('server')
        ->and($m->field('notifiable_id'))->toBe(5)
        // Die Alt-Spalten bleiben unberuehrt — Laravels eigene Abfrage
        // ueber `$user->notifications()` findet die Zeile weiterhin.
        ->and($m->getAttribute('notifiable_type'))->toBe('user')
        ->and($m->getAttribute('notifiable_id'))->toBe($this->ich->id);
});

it('buendelt auf der Standardform genauso', function (): void {
    foreach (range(1, 4) as $i) {
        Notification::create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\AppNotification',
            'notifiable_type' => 'user',
            'notifiable_id' => $this->ich->id,
            'data' => '{}',
            'user_id' => $this->ich->id,
            'kind' => 'infra.server',
            'title' => 'Server nicht erreichbar',
            'subject_type' => 'server',
            'subject_id' => 5,
        ]);
    }

    $liste = app(NotificationFeed::class)->for($this->ich);

    expect($liste['items'])->toHaveCount(1)
        ->and($liste['items'][0]['bundle_count'])->toBe(4)
        ->and($liste['unread_count'])->toBe(4);
});

it('handelt auf UUID-Kennungen', function (): void {
    $ids = collect(range(1, 3))->map(function (): string {
        $uuid = (string) Str::uuid();

        Notification::create([
            'id' => $uuid,
            'type' => 'App\\Notifications\\AppNotification',
            'notifiable_type' => 'user',
            'notifiable_id' => $this->ich->id,
            'data' => '{}',
            'user_id' => $this->ich->id,
            'kind' => 'infra.server',
            'title' => 'Server nicht erreichbar',
        ]);

        return $uuid;
    })->all();

    expect(app(NotificationActions::class)->markRead($this->ich, $ids))->toBe(3)
        ->and(Notification::query()->forUser($this->ich->id)->unread()->count())->toBe(0);
});

it('stellt ueber den Notifier zu, wenn die Alt-Spalten nachgefuellt werden', function (): void {
    // Die Standardtabelle hat `notifiable_*` als NOT NULL. Das Paket kennt
    // diese Spalten nicht — das Produkt fuellt sie, sonst scheitert das
    // INSERT. Hier als Modell-Haken nachgestellt, wie ihn AI Brain bekommt.
    Notification::creating(function (Notification $m): void {
        $m->setAttribute('id', $m->getAttribute('id') ?? (string) Str::uuid());
        $m->setAttribute('notifiable_type', 'user');
        $m->setAttribute('notifiable_id', $m->field('user_id'));
        $m->setAttribute('type', $m->getAttribute('type') ?? 'App\\Notifications\\AppNotification');
        $m->setAttribute('data', $m->getAttribute('data') ?? '{}');
    });

    $m = app(Notifier::class)->send(
        to: $this->ich,
        type: 'infra.server',
        title: 'Server nicht erreichbar',
    );

    expect($m)->not->toBeNull()
        ->and($m->getKey())->toBeString()
        ->and($m->getAttribute('notifiable_id'))->toBe($this->ich->id);

    Notification::flushEventListeners();
});

it('erzeugt den UUID selbst, wenn niemand einen mitgibt', function (): void {
    // Solange das Paket nur liest, faellt das nicht auf: Geschrieben wird
    // dann ueber Laravels Notification-Weg, und der setzt den Schluessel.
    // Beim Spiegeln legt das Paket zum ersten Mal SELBST an — und dort
    // scheiterte das INSERT an „NOT NULL constraint failed: id".
    $m = Notification::create([
        'user_id' => $this->ich->id,
        'type' => 'App\\Notifications\\AppNotification',
        'notifiable_type' => 'user',
        'notifiable_id' => $this->ich->id,
        'data' => '{}',
        'kind' => 'infra.server',
        'title' => 'Ohne mitgegebene Kennung',
    ]);

    expect($m->getKey())->toBeString()
        ->and($m->getKey())->toHaveLength(36)
        ->and(Notification::query()->whereKey($m->getKey())->exists())->toBeTrue();
});

it('laesst eine mitgegebene Kennung in Ruhe', function (): void {
    $eigene = (string) Str::uuid();

    $m = Notification::create([
        'id' => $eigene,
        'user_id' => $this->ich->id,
        'type' => 'App\\Notifications\\AppNotification',
        'notifiable_type' => 'user',
        'notifiable_id' => $this->ich->id,
        'data' => '{}',
        'kind' => 'infra.server',
        'title' => 'Mit eigener Kennung',
    ]);

    expect($m->getKey())->toBe($eigene);
});

it('fuellt die Alt-Spalten der Standardform, wenn das Paket selbst anlegt', function (): void {
    // Die Standardtabelle fuehrt `type`, `notifiable_*` und `data` als NOT
    // NULL. Das Paket kennt sie nicht — ohne diese Bruecke scheitert jedes
    // INSERT, das nicht ueber Laravels Notification-Weg laeuft.
    //
    // Dreimal im Produkt nachgebaut waere es dreimal dieselbe Gelegenheit,
    // es falsch zu machen.
    config()->set('notifications.legacy_columns', [
        'type' => 'App\\Notifications\\AppNotification',
        'notifiable_type' => 'user',
        'notifiable_id' => fn (Notification $m) => $m->field('user_id'),
        'data' => '{}',
    ]);

    $m = Notification::create([
        'user_id' => $this->ich->id,
        'kind' => 'infra.server',
        'title' => 'Ohne Alt-Spalten uebergeben',
    ]);

    expect($m->getAttribute('type'))->toBe('App\\Notifications\\AppNotification')
        ->and($m->getAttribute('notifiable_type'))->toBe('user')
        ->and($m->getAttribute('notifiable_id'))->toBe($this->ich->id)
        // Und die Zeile ist ueber Laravels eigenen Weg auffindbar.
        ->and(Notification::query()->whereKey($m->getKey())->exists())->toBeTrue();
});

it('ueberschreibt eine Alt-Spalte nicht, die schon gesetzt ist', function (): void {
    config()->set('notifications.legacy_columns', ['type' => 'Vorgabe']);

    $m = Notification::create([
        'type' => 'Ausdruecklich gesetzt',
        'notifiable_type' => 'user',
        'notifiable_id' => $this->ich->id,
        'data' => '{}',
        'user_id' => $this->ich->id,
        'kind' => 'infra.server',
        'title' => 'T',
    ]);

    expect($m->getAttribute('type'))->toBe('Ausdruecklich gesetzt');
});

it('loest eine Feld-Referenz in den Alt-Spalten auf', function (): void {
    // `@user_id` statt einer Closure: Die Konfiguration muss
    // `php artisan config:cache` ueberleben, und das tut eine Closure nicht.
    config()->set('notifications.legacy_columns', [
        'type' => 'App\\Notifications\\AppNotification',
        'notifiable_type' => 'user',
        'notifiable_id' => '@user_id',
        'data' => '{}',
    ]);

    $m = Notification::create([
        'user_id' => $this->ich->id,
        'kind' => 'infra.server',
        'title' => 'Mit Feld-Referenz',
    ]);

    expect($m->getAttribute('notifiable_id'))->toBe($this->ich->id)
        ->and($m->getAttribute('notifiable_type'))->toBe('user');
});

it('haelt die Alt-Spalten-Konfiguration zwischenspeicherbar', function (): void {
    // Die Probe aufs Exempel: `var_export` ist, was config:cache tut. Steht
    // dort eine Closure, bricht der Befehl ab — und ein Release wird
    // ausgecheckt und nie umgehaengt.
    config()->set('notifications.legacy_columns', [
        'type' => 'App\\Notifications\\AppNotification',
        'notifiable_id' => '@user_id',
    ]);

    $ausgabe = var_export(config('notifications.legacy_columns'), true);

    expect($ausgabe)->toContain('@user_id')
        ->and($ausgabe)->not->toContain('Closure');
});
