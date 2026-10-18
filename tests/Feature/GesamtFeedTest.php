<?php

use Illuminate\Support\Facades\Cache;
use Peppermint\Notifications\Delivery\Notifier;
use Peppermint\Notifications\Feed\FremdeMeldungen;
use Peppermint\Notifications\Feed\GesamtFeed;
use Peppermint\Notifications\Tests\Support\User;
use Peppermint\Notifications\Types\TypeRegistry;

/**
 * Der Posteingang über alle Systeme (#8186).
 *
 * Eigene Zeilen plus geholte — nicht kopierte. Die Holer-Seite wird hier
 * ersetzt; dass sie wirklich über den Gateway geht, hat ihr eigener Test.
 */
beforeEach(function (): void {
    Cache::flush();

    app(TypeRegistry::class)->registerMany([
        ['key' => 'task.assigned', 'label' => 'Aufgabe zugewiesen', 'icon' => 'user-plus', 'color' => 'blue'],
    ]);

    $this->ich = User::create(['name' => 'Ich', 'email' => 'ich@beispiel.test']);
});

/** Die Holer-Seite durch feste Antworten ersetzen. */
function fremdeLiefern(array $zeilen, array $stumm = [], ?int $ungelesen = null): void
{
    // Die Zahl kommt vom befragten System selbst — nicht aus der Laenge der
    // Liste. Ohne Angabe wird gezaehlt, was ungelesen aussieht.
    $ungelesen ??= count(array_filter($zeilen, fn (array $z): bool => empty($z['is_read'])));

    $attrappe = Mockery::mock(FremdeMeldungen::class);
    $attrappe->shouldReceive('holen')->andReturn([
        'items' => $zeilen,
        'unread_count' => $ungelesen,
        'unreachable' => $stumm,
    ]);

    app()->instance(FremdeMeldungen::class, $attrappe);
}

function fremdeZeile(array $u = []): array
{
    return array_merge([
        'id' => 'f1', 'ids' => ['f1'], 'bundle_count' => 1,
        'type' => 'note.shared', 'type_label' => 'Notiz geteilt',
        'title' => 'Aus dem CRM', 'message' => null, 'icon' => 'bell', 'color' => 'gray',
        'link' => 'https://crm.test/notes/1', 'is_read' => false,
        'created_at' => now()->subMinutes(5)->toIso8601String(),
        'source' => 'crm', 'source_label' => 'CRM',
    ], $u);
}

it('zeigt eigene und geholte Zeilen zusammen', function (): void {
    app(Notifier::class)->send(to: $this->ich, type: 'task.assigned', title: 'Von hier');
    fremdeLiefern([fremdeZeile()]);

    $feed = app(GesamtFeed::class)->for($this->ich);

    expect($feed['items'])->toHaveCount(2)
        ->and(array_column($feed['items'], 'title'))->toContain('Von hier', 'Aus dem CRM');
});

it('zaehlt die ungelesenen beider Seiten zusammen', function (): void {
    app(Notifier::class)->send(to: $this->ich, type: 'task.assigned', title: 'Von hier');
    fremdeLiefern([fremdeZeile(), fremdeZeile(['id' => 'f2', 'is_read' => true])]);

    expect(app(GesamtFeed::class)->for($this->ich)['unread_count'])->toBe(2);
});

it('sortiert ueber die Systeme hinweg nach Zeit, nicht nach Herkunft', function (): void {
    // Sonst stuenden erst alle eigenen und dann alle fremden — die Liste
    // waere nach Herkunft geordnet statt nach dem, was zuletzt passiert ist.
    app(Notifier::class)->send(to: $this->ich, type: 'task.assigned', title: 'Aelter');
    \Peppermint\Notifications\Models\Notification::query()->update(['created_at' => now()->subHour()]);

    fremdeLiefern([fremdeZeile(['title' => 'Neuer', 'created_at' => now()->toIso8601String()])]);

    expect(app(GesamtFeed::class)->for($this->ich)['items'][0]['title'])->toBe('Neuer');
});

it('stellt Ungelesenes vor Gelesenes, ueber Systemgrenzen hinweg', function (): void {
    app(Notifier::class)->send(to: $this->ich, type: 'task.assigned', title: 'Gelesen')->markAsRead();

    fremdeLiefern([fremdeZeile(['title' => 'Ungelesen', 'created_at' => now()->subDay()->toIso8601String()])]);

    expect(app(GesamtFeed::class)->for($this->ich)['items'][0]['title'])->toBe('Ungelesen');
});

it('nennt die Systeme, die gerade nicht antworten', function (): void {
    // Eine still verkuerzte Liste sieht aus wie „da ist nichts".
    fremdeLiefern([], ['Verwaltung']);

    expect(app(GesamtFeed::class)->for($this->ich)['unreachable'])->toBe(['Verwaltung']);
});

it('holt die fremden Zeilen nicht bei jedem Aufruf neu', function (): void {
    // Der Abruf geht uebers Netz an jedes angebundene System, und das
    // Abzeichen haengt an JEDER Seite.
    $attrappe = Mockery::mock(FremdeMeldungen::class);
    $attrappe->shouldReceive('holen')->once()->andReturn(['items' => [], 'unread_count' => 0, 'unreachable' => []]);
    app()->instance(FremdeMeldungen::class, $attrappe);

    app(GesamtFeed::class)->for($this->ich);
    app(GesamtFeed::class)->for($this->ich);
    app(GesamtFeed::class)->unreadCount($this->ich);

    expect(true)->toBeTrue();
});

it('holt auf Verlangen doch neu', function (): void {
    $attrappe = Mockery::mock(FremdeMeldungen::class);
    $attrappe->shouldReceive('holen')->twice()->andReturn(['items' => [], 'unread_count' => 0, 'unreachable' => []]);
    app()->instance(FremdeMeldungen::class, $attrappe);

    app(GesamtFeed::class)->for($this->ich);
    app(GesamtFeed::class)->for($this->ich, frisch: true);

    expect(true)->toBeTrue();
});

it('fragt gar nicht erst fuer jemanden ohne Adresse', function (): void {
    $ohne = User::create(['name' => 'Ohne']);

    $attrappe = Mockery::mock(FremdeMeldungen::class);
    $attrappe->shouldReceive('holen')->never();
    app()->instance(FremdeMeldungen::class, $attrappe);

    expect(app(GesamtFeed::class)->for($ohne)['items'])->toBe([]);
});

it('kappt JE SYSTEM, nicht ueber die ganze Liste', function (): void {
    // Der Fall, der das aufgedeckt hat: AI Brain hatte 3.991 ungelesene
    // eigene Meldungen. Mit einer globalen Obergrenze stammten die 50
    // neuesten alle von dort — und die Zeilen aus CRM und Verwaltung fielen
    // hinten heraus, bei einer Liste, die „alles aus allen Systemen" zeigen
    // soll.
    config()->set('notifications.feed.max_items', 3);

    foreach (range(1, 10) as $i) {
        app(Notifier::class)->send(to: $this->ich, type: 'task.assigned', title: "Eigen {$i}");
    }

    fremdeLiefern([
        fremdeZeile(['id' => 'f1', 'title' => 'Fremd 1', 'created_at' => now()->subDays(9)->toIso8601String()]),
        fremdeZeile(['id' => 'f2', 'title' => 'Fremd 2', 'created_at' => now()->subDays(10)->toIso8601String()]),
    ]);

    $zeilen = app(GesamtFeed::class)->for($this->ich)['items'];
    $titel = array_column($zeilen, 'title');

    expect(count(array_filter($titel, fn ($t) => str_starts_with($t, 'Eigen'))))->toBe(3)
        // Die fremden sind VIEL aelter und waeren bei globaler Kappung weg.
        ->and($titel)->toContain('Fremd 1', 'Fremd 2');
});

it('macht damit den Herkunfts-Filter erst brauchbar', function (): void {
    // Der Filter arbeitet im Browser auf dem, was angekommen ist. Wer auf
    // „CRM" klickt und nichts sieht, obwohl es dort etwas gibt, hat einen
    // Filter, der schlimmer ist als keiner.
    config()->set('notifications.feed.max_items', 2);

    foreach (range(1, 20) as $i) {
        app(Notifier::class)->send(to: $this->ich, type: 'task.assigned', title: "Eigen {$i}");
    }

    fremdeLiefern([fremdeZeile(['created_at' => now()->subYear()->toIso8601String()])]);

    $herkuenfte = array_column(app(GesamtFeed::class)->for($this->ich)['items'], 'source');

    expect(array_filter($herkuenfte))->not->toBeEmpty();
});

it('nimmt die Zahl der Ungelesenen vom befragten System, nicht aus der Liste', function (): void {
    // Die Zeilen sind gebuendelt („17 × Service down" ist EINE Zeile) und
    // gekappt. Wer sie zaehlt, bekommt in jedem System eine andere Summe
    // fuer dieselbe Sache — im Manager stand 206, im CRM 102.
    app(Notifier::class)->send(to: $this->ich, type: 'task.assigned', title: 'Eigen');

    // Eine Zeile, aber das System meldet 17 Ungelesene dahinter.
    fremdeLiefern([fremdeZeile()], [], ungelesen: 17);

    expect(app(GesamtFeed::class)->for($this->ich)['unread_count'])->toBe(18);
});
