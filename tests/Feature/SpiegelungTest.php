<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Peppermint\Notifications\Delivery\Notifier;
use Peppermint\Notifications\Events\NotificationCreated;
use Peppermint\Notifications\Feed\NotificationFeed;
use Peppermint\Notifications\Mirroring\BrainMirror;
use Peppermint\Notifications\Mirroring\MirrorPayload;
use Peppermint\Notifications\Mirroring\MirrorReceiver;
use Peppermint\Notifications\Models\Notification;
use Peppermint\Notifications\Tests\Support\User;
use Peppermint\Notifications\Tests\Support\Widget;
use Peppermint\Notifications\Types\NotificationType;
use Peppermint\Notifications\Types\TypeRegistry;

/**
 * Die Spiegelung: dieselbe Meldung in allen Produkten.
 *
 * Geprüft wird beides — was hinausgeht und was hereinkommt. Die Brücke
 * selbst (`peppermint/ai-brain-bridge`) ist hier nicht installiert; sie ist
 * eine *suggest*-Abhängigkeit. Genau deshalb muss der Fall „Brücke fehlt"
 * mitgeprüft werden: Er ist im Paket-Test der Normalfall und in einem
 * falsch konfigurierten Produkt der teure.
 */
beforeEach(function (): void {
    $this->empfaenger = User::create(['name' => 'Empfaenger', 'email' => 'empfaenger@beispiel.test']);
    config()->set('notifications.user_model', User::class);
});

/* ------------------------------------------------- die sendende Seite */

describe('Senden', function () {
    it('spiegelt NICHTS, solange der Hub auf local steht', function (): void {
        // Die Zusicherung aus dem README: Wer das Paket allein benutzt, hat
        // keine Verbindung nach draussen.
        //
        // Geprueft wird der DIENST, nicht die Verdrahtung im ServiceProvider:
        // Der bootet, bevor ein Test die Konfiguration aendern kann, und ein
        // Test dagegen misst nichts — per Mutationsprobe aufgefallen (Riegel
        // im Provider entfernt, alle 12 Tests blieben gruen).
        //
        // Der Dienst ist ohnehin die Stelle, auf die es ankommt: Er ist die
        // letzte vor dem Netz.
        config()->set('notifications.hub', 'local');
        config()->set('notifications.mirror.types', ['*']);

        Log::spy();

        app(BrainMirror::class)->handle(
            new NotificationCreated(
                Notification::create([
                    'user_id' => $this->empfaenger->id,
                    'type' => 'task.assigned',
                    'title' => 'Zugewiesen',
                ])
            )
        );

        // Kein Wort ueber eine fehlende Bruecke — der Dienst hat gar nicht
        // erst nachgesehen.
        Log::shouldNotHaveReceived('warning');
    });

    it('spiegelt nur Arten, die ausdruecklich angemeldet sind', function (): void {
        // Die Vorgabe ist eine leere Liste. „Neue E-Mail in Konto 3" hat im
        // CRM nichts verloren, und das Brain allein schreibt 243
        // Infrastruktur-Meldungen am Tag.
        config()->set('notifications.hub', 'brain');
        config()->set('notifications.mirror.types', ['mention']);

        Log::spy();

        $melde = fn (string $art) => app(BrainMirror::class)->handle(
            new NotificationCreated(
                Notification::create([
                    'user_id' => $this->empfaenger->id,
                    'type' => $art,
                    'title' => 'Titel',
                ])
            )
        );

        // Nicht angemeldet: kommt gar nicht erst bis zur Bruecke.
        $melde('infra.server');
        Log::shouldNotHaveReceived('warning');

        // Angemeldet: versucht es — und meldet, dass die Bruecke fehlt.
        $melde('mention');
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m): bool => str_contains($m, 'ai-brain-bridge'));
    });

    it('meldet eine Meldung ohne Empfaenger-Adresse nicht weiter, sondern protokolliert sie', function (): void {
        // Ohne Adresse kann kein anderes Produkt den Menschen finden. Das ist
        // keine Eigenschaft der Meldung, sondern eine Luecke im Nutzer-Satz —
        // und die gehoert ins Protokoll, nicht in die Stille.
        config()->set('notifications.hub', 'brain');
        config()->set('notifications.mirror.types', ['*']);

        $ohneAdresse = User::create(['name' => 'Ohne Adresse']);

        Log::spy();

        app(BrainMirror::class)->handle(
            new NotificationCreated(
                Notification::create([
                    'user_id' => $ohneAdresse->id,
                    'type' => 'mention',
                    'title' => 'Titel',
                ])
            )
        );

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m): bool => str_contains($m, 'ohne E-Mail'));
    });

    it('meldet laut, wenn der Hub brain ist und die Bruecke fehlt', function (): void {
        // Eine Fehlkonfiguration, kein Randfall: Jemand haelt die Spiegelung
        // fuer aktiv, waehrend nichts gespiegelt wird. Stilles Nichtstun
        // waere hier das Schlimmste.
        config()->set('notifications.hub', 'brain');
        config()->set('notifications.mirror.types', ['*']);

        Log::spy();

        app(BrainMirror::class)->handle(
            new NotificationCreated(
                Notification::create([
                    'user_id' => $this->empfaenger->id,
                    'type' => 'task.assigned',
                    'title' => 'Zugewiesen',
                ])
            )
        );

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m): bool => str_contains($m, 'ai-brain-bridge'));
    });

    it('meldet die fehlende Bruecke EINMAL, nicht je Meldung', function (): void {
        // Das AI Brain schreibt 243 Meldungen am Tag. Eine Warnung pro Stueck
        // macht aus einem Hinweis eine Flut, und die naechste echte Meldung
        // geht darin unter.
        config()->set('notifications.hub', 'brain');
        config()->set('notifications.mirror.types', ['*']);

        Log::spy();

        $spiegel = app(BrainMirror::class);

        foreach (range(1, 5) as $i) {
            $spiegel->handle(
                new NotificationCreated(
                    Notification::create([
                        'user_id' => $this->empfaenger->id,
                        'type' => 'mention',
                        'title' => "Meldung {$i}",
                    ])
                )
            );
        }

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m): bool => str_contains($m, 'ai-brain-bridge'))
            ->once();
    });
});

/* ---------------------------------------------------- die Nutzlast */

describe('Nutzlast', function () {
    it('macht den Verweis absolut — sonst zeigt er drueben ins Leere', function (): void {
        // `/tasks/5` ist im CRM eine Seite, die es nicht gibt. Die Meldung
        // muss zurueck zu der Stelle fuehren, an der die Sache wirklich liegt.
        $m = Notification::create([
            'user_id' => $this->empfaenger->id,
            'type' => 'task.assigned',
            'title' => 'Zugewiesen',
            'link' => '/tasks/5',
        ]);

        $nutzlast = MirrorPayload::fromNotification($m, 'wer@beispiel.test', 'manager', 'https://pm.beispiel.test');

        expect($nutzlast->link)->toBe('https://pm.beispiel.test/tasks/5');
    });

    it('laesst einen bereits absoluten Verweis in Ruhe', function (): void {
        $m = Notification::create([
            'user_id' => $this->empfaenger->id,
            'type' => 'task.assigned',
            'title' => 'Zugewiesen',
            'link' => 'https://anderswo.test/x',
        ]);

        expect(MirrorPayload::fromNotification($m, 'wer@beispiel.test', 'manager', 'https://pm.beispiel.test')->link)
            ->toBe('https://anderswo.test/x');
    });

    it('gilt ohne Empfaenger-Adresse oder Titel als unvollstaendig', function (): void {
        expect((new MirrorPayload('', 'x', 'Titel'))->istVollstaendig())->toBeFalse()
            ->and((new MirrorPayload('wer@beispiel.test', 'x', ''))->istVollstaendig())->toBeFalse()
            ->and((new MirrorPayload('wer@beispiel.test', 'x', 'Titel'))->istVollstaendig())->toBeTrue();
    });

    it('ueberlebt den Weg durch ein Array unveraendert', function (): void {
        $vorher = new MirrorPayload(
            recipientEmail: 'wer@beispiel.test', kind: 'task.assigned', title: 'Zugewiesen',
            message: 'Text', link: 'https://x.test/1', icon: 'user-plus', color: 'blue',
            origin: 'manager', originId: 42, data: ['extra' => true],
        );

        $nachher = MirrorPayload::fromArray($vorher->toArray());

        expect($nachher->toArray())->toBe($vorher->toArray());
    });
});

/*
 * Die empfangende Seite gibt es seit v0.19.0 nicht mehr.
 *
 * Bis dahin stand hier ein `MirrorReceiver`, der eine fremde Meldung lokal
 * anlegte, dazu Tests fuer Mehrfach-Empfang, fehlende Spalten und den Weg
 * ueber die Bruecke. Das war der falsche Aufbau: Es wird nirgendwohin
 * kopiert. Fremde Meldungen werden GELESEN — die Tests dafuer stehen in
 * `tests/Feature/GesamtFeedTest.php` und `tests/Feature/FremdeMeldungenTest.php`.
 *
 * Was hier bleibt, ist das Signal nach draussen: Es traegt keine Daten in
 * ein anderes System, es stoesst dort den Push an.
 */
