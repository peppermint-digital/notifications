<?php

use Peppermint\Inbox\Quellen\NachTypenFilterbar;
use Peppermint\Inbox\Quellen\Quellenverzeichnis;
use Peppermint\Notifications\Delivery\Notifier;
use Peppermint\Notifications\Feed\NotificationActions;
use Peppermint\Notifications\Feed\NotificationFeed;
use Peppermint\Notifications\Inbox\MeldungsQuelle;
use Peppermint\Notifications\Mcp\ListNotificationsTool;
use Peppermint\Notifications\Models\Notification;
use Peppermint\Notifications\Tests\Support\User;

/**
 * Nur bestimmte Arten (pm #10227).
 *
 * Ein anderes System zeigt nur einen Teil dieser Meldungen — im Manager
 * Persoenliches aus AI Brain, keine Betriebsmeldungen. Bastian, 25.11.2026:
 * „Ja, mach alles." Weil die Zahlen hier gerechnet werden, filtert dieses
 * Paket selbst.
 */
beforeEach(function (): void {
    $this->ich = User::create(['name' => 'Ich', 'email' => 'ich@beispiel.test']);
    $senden = fn (string $typ, string $titel) => app(Notifier::class)->send(to: $this->ich, type: $typ, title: $titel);

    $senden('task.done', 'Aufgabe erledigt');
    $senden('app.health', 'App krank 1');
    $senden('infra.server', 'Server weg');
    $senden('', 'Ohne Art');
});

it('zaehlt und listet nur die genannten Arten, ohne Art eingeschlossen', function (): void {
    $feed = app(NotificationFeed::class)->nurTypen(['task.done', '']);

    expect($feed->unreadCount($this->ich))->toBe(2)
        ->and(collect($feed->for($this->ich)['items'])->pluck('title')->sort()->values()->all())
        ->toBe(['Aufgabe erledigt', 'Ohne Art']);
});

it('laesst den gemeinsamen Feed unveraendert — der Filter gilt nur fuer die Kopie', function (): void {
    app(NotificationFeed::class)->nurTypen(['task.done']);

    expect(app(NotificationFeed::class)->unreadCount($this->ich))->toBe(4);
});

it('haekt und loescht „alle" nur in den genannten Arten', function (): void {
    $aktionen = app(NotificationActions::class);

    expect($aktionen->markAllRead($this->ich, ['task.done']))->toBe(1)
        ->and(Notification::query()->forUser($this->ich->id)->unread()->count())->toBe(3)
        ->and($aktionen->deleteAll($this->ich, ['']))->toBe(1)
        ->and(Notification::query()->forUser($this->ich->id)->count())->toBe(3);
});

it('trifft mit einer leeren Liste nichts', function (): void {
    expect(app(NotificationFeed::class)->nurTypen([])->unreadCount($this->ich))->toBe(0)
        ->and(app(NotificationActions::class)->deleteAll($this->ich, []))->toBe(0);
});

it('gibt der Posteingangs-Quelle denselben Filter — Liste, Bestand, alle', function (): void {
    expect(app(MeldungsQuelle::class))->toBeInstanceOf(NachTypenFilterbar::class);

    // Eine Instanz: In diesem Testaufbau ist das Verzeichnis kein Singleton.
    $verzeichnis = app(Quellenverzeichnis::class);
    $verzeichnis->anmelden(MeldungsQuelle::class);

    $gesammelt = $verzeichnis->sammeln($this->ich, 50, ['task.done']);

    expect($gesammelt['totals'])->toBe(['notification' => 1])
        ->and($gesammelt['items'])->toHaveCount(1)
        ->and($verzeichnis->allesAlsGelesen($this->ich, null, ['task.done']))->toBe(1)
        // Die Betriebsmeldungen sind unberuehrt.
        ->and(Notification::query()->forUser($this->ich->id)->unread()->count())->toBe(3)
        ->and($verzeichnis->allesLoeschen($this->ich, 'notification', ['app.health', 'infra.server']))->toBe(2)
        ->and(Notification::query()->forUser($this->ich->id)->count())->toBe(2);
});

it('filtert list-notifications-tool nach types', function (): void {
    $this->actingAs($this->ich);

    $antwort = app(ListNotificationsTool::class)->handle(
        new \Laravel\Mcp\Request(['types' => ['task.done']]),
        app(NotificationFeed::class),
    );
    $daten = json_decode((string) $antwort->content(), true);

    expect($daten['unread_count'])->toBe(1)
        ->and($daten['items'])->toHaveCount(1);
});

it('schickt beim Holen aus AI Brain die gezeigten Arten mit (inbox.fremde_typen)', function (): void {
    config(['inbox.fremde_typen' => ['ai-brain' => ['task.done', '']]]);

    $hub = new class
    {
        public array $aufrufe = [];

        public function call(string $werkzeug, array $argumente = []): array
        {
            $this->aufrufe[] = [$werkzeug, $argumente];

            return ['items' => [], 'unread_count' => 0];
        }
    };
    \Peppermint\AiBrainBridge\Facades\AiBrain::swap($hub);

    (new \Peppermint\Notifications\Feed\BridgeRemoteSource)->holen('ai-brain', 'ich@beispiel.test', 50);

    expect($hub->aufrufe)->toBe([['list-notifications-tool', ['limit' => 50, 'types' => ['task.done', '']]]]);
});
