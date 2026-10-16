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

/* ------------------------------------------------ die empfangende Seite */

describe('Empfangen', function () {
    function spiegel(array $ueberschreiben = []): array
    {
        return array_merge([
            'recipient_email' => 'empfaenger@beispiel.test',
            'kind' => 'task.assigned',
            'title' => 'Aufgabe zugewiesen',
            'message' => 'Im Manager.',
            'link' => 'https://pm.beispiel.test/tasks/5',
            'icon' => 'user-plus',
            'color' => 'blue',
            'origin' => 'peppermint-manager',
            'origin_id' => 4711,
        ], $ueberschreiben);
    }

    it('legt eine gespiegelte Meldung lokal an', function (): void {
        $m = app(MirrorReceiver::class)->empfangen(spiegel());

        expect($m)->not->toBeNull()
            ->and($m->field('user_id'))->toBe($this->empfaenger->id)
            ->and($m->field('type'))->toBe('task.assigned')
            ->and($m->field('link'))->toBe('https://pm.beispiel.test/tasks/5')
            ->and($m->field('data')['mirror_origin'])->toBe('peppermint-manager');
    });

    it('legt NICHTS an, wenn es den Menschen hier nicht gibt', function (): void {
        // Der Normalfall, kein Fehler: Nicht jeder hat in jedem Produkt ein
        // Konto. Eine Zeile „fuer irgendwen" zaehlte im Abzeichen eines
        // Fremden mit.
        $m = app(MirrorReceiver::class)->empfangen(spiegel(['recipient_email' => 'niemand@beispiel.test']));

        expect($m)->toBeNull()
            ->and(Notification::count())->toBe(0);
    });

    it('legt dieselbe Spiegelung nicht zweimal an', function (): void {
        // Der Komm-Layer liefert idempotent, aber ein Wiederholungsversuch
        // nach einer Zeitueberschreitung kann durchkommen.
        app(MirrorReceiver::class)->empfangen(spiegel());
        $zweite = app(MirrorReceiver::class)->empfangen(spiegel());

        expect($zweite)->toBeNull()
            ->and(Notification::count())->toBe(1);
    });

    it('unterscheidet zwei verschiedene Meldungen desselben Absenders', function (): void {
        app(MirrorReceiver::class)->empfangen(spiegel(['origin_id' => 1]));
        app(MirrorReceiver::class)->empfangen(spiegel(['origin_id' => 2]));

        expect(Notification::count())->toBe(2);
    });

    it('verwirft eine unvollstaendige Nutzlast, statt eine leere Zeile anzulegen', function (): void {
        expect(app(MirrorReceiver::class)->empfangen(spiegel(['title' => ''])))->toBeNull()
            ->and(Notification::count())->toBe(0);
    });

    it('fuegt sich in die Buendelung ein wie eine eigene Meldung', function (): void {
        $widget = Widget::create(['title' => 'Aufgabe']);

        // Eine eigene …
        app(Notifier::class)->send(
            to: $this->empfaenger, type: 'task.assigned',
            title: 'Aufgabe zugewiesen', subject: $widget,
        );

        // … und eine gespiegelte, die an nichts Lokalem haengt.
        app(MirrorReceiver::class)->empfangen(spiegel());

        $liste = app(NotificationFeed::class)->for($this->empfaenger);

        // Zwei Zeilen: Die gespiegelte hat keinen lokalen Gegenstand und
        // gruppiert deshalb ueber ihren Titel.
        expect($liste['unread_count'])->toBe(2)
            ->and($liste['items'])->toHaveCount(2);
    });
});

describe('Die Beschriftung reist mit', function () {
    it('zeigt die Beschriftung des Absenders, nicht das Rueckfall-Wort', function (): void {
        // Der Schluessel gehoert dem Absender: `task_assigned` ist ein
        // Manager-Wort, das Brain nennt seine Arten `task.done`. Das
        // Verzeichnis hier kennt ihn nicht — ohne die mitgereiste
        // Beschriftung staende in der Zeile „Benachrichtigung", und das
        // sagt nichts.
        app(TypeRegistry::class)->register(
            new NotificationType('infra.server', 'Server-Zustand', 'server', 'red')
        );

        app(MirrorReceiver::class)->empfangen([
            'recipient_email' => 'empfaenger@beispiel.test',
            'kind' => 'task_assigned',
            'label' => 'Aufgabe zugewiesen',
            'title' => 'Angebot nachfassen',
            'icon' => 'user-plus',
            'color' => 'blue',
            'origin' => 'peppermint-manager',
            'origin_id' => 1,
        ]);

        $zeile = app(NotificationFeed::class)->for($this->empfaenger)['items'][0];

        expect($zeile['type_label'])->toBe('Aufgabe zugewiesen')
            ->and($zeile['type_label'])->not->toBe('Benachrichtigung')
            // Symbol und Farbe standen schon vorher an der Zeile.
            ->and($zeile['icon'])->toBe('user-plus')
            ->and($zeile['color'])->toBe('blue');
    });

    it('nimmt die eigene Beschriftung, wo die Art bekannt ist', function (): void {
        // Eine eigene Meldung bleibt unberuehrt — die mitgereiste
        // Beschriftung gilt nur fuer Gespiegeltes.
        app(TypeRegistry::class)->register(
            new NotificationType('mention', 'Erwaehnung', 'at-sign', 'cyan')
        );

        app(Notifier::class)->send(to: $this->empfaenger, type: 'mention', title: 'Erwaehnt');

        $zeile = app(NotificationFeed::class)->for($this->empfaenger)['items'][0];

        expect($zeile['type_label'])->toBe('Erwaehnung');
    });

    it('nimmt die Beschriftung beim Senden aus dem eigenen Verzeichnis mit', function (): void {
        app(TypeRegistry::class)->register(
            new NotificationType('mention', 'Erwaehnung', 'at-sign', 'cyan')
        );

        $m = Notification::create([
            'user_id' => $this->empfaenger->id,
            'type' => 'mention',
            'title' => 'Erwaehnt',
        ]);

        $nutzlast = MirrorPayload::fromNotification($m, 'wer@beispiel.test', 'brain');

        expect($nutzlast->label)->toBe('Erwaehnung');
    });
});

describe('Empfang ueber die Bruecke', function () {
    it('legt an, was die Bruecke als Ereignis hereinreicht', function (): void {
        // Die Luecke, die lange offen war: Das AI Brain empfaengt ueber einen
        // eigenen Endpunkt (es ist der Hub), ein PRODUKT aber ueber das
        // Bruecken-Ereignis. Solange man nur zum Hub schickt, faellt das
        // nicht auf — erst wenn ein zweites Produkt abonniert, und dann
        // steht dort nichts.
        //
        // Das Bruecken-Paket ist hier nicht installiert, also wird das
        // Ereignis nachgestellt: eine Klasse mit `type` und `payload`, genau
        // was der Horcher liest.
        $ereignis = new class
        {
            public object $event;

            public function __construct()
            {
                $this->event = (object) [
                    'type' => BrainMirror::EVENT_TYPE,
                    'payload' => [
                        'recipient_email' => 'empfaenger@beispiel.test',
                        'kind' => 'task_assigned',
                        'label' => 'Aufgabe zugewiesen',
                        'title' => 'Angebot nachfassen',
                        'origin' => 'peppermint-manager',
                        'origin_id' => 99,
                    ],
                ];
            }
        };

        // Der Horcher, wie ihn der ServiceProvider einhaengt.
        $verarbeiten = function (object $e): void {
            if (($e->event->type ?? null) !== BrainMirror::EVENT_TYPE) {
                return;
            }

            app(MirrorReceiver::class)->empfangen((array) ($e->event->payload ?? []));
        };

        $verarbeiten($ereignis);

        expect(Notification::count())->toBe(1)
            ->and(Notification::first()->field('title'))->toBe('Angebot nachfassen');
    });

    it('laesst andere Ereignisarten in Ruhe', function (): void {
        $fremd = (object) ['event' => (object) ['type' => 'deal.won', 'payload' => ['x' => 1]]];

        $verarbeiten = function (object $e): void {
            if (($e->event->type ?? null) !== BrainMirror::EVENT_TYPE) {
                return;
            }

            app(MirrorReceiver::class)->empfangen((array) ($e->event->payload ?? []));
        };

        $verarbeiten($fremd);

        expect(Notification::count())->toBe(0);
    });
});

describe('Fehlende Spalten', function () {
    it('legt an, auch wenn die Zieltabelle eine Spalte nicht hat', function (): void {
        // Die vier Produkte haben nicht dieselben Spalten: Der Manager fuehrt
        // keine `color` — dort kommt die Farbe aus dem Vokabular. Eine
        // gespiegelte Meldung bringt sie trotzdem mit.
        //
        // Beim ersten Livetest (16.10.2026) scheiterte das INSERT an genau
        // dieser einen Spalte. Signatur, Route und Horcher liefen; die
        // Meldung kam trotzdem nirgends an.
        Schema::table('notifications', function ($t): void {
            $t->dropColumn('color');
        });

        $m = app(MirrorReceiver::class)->empfangen([
            'recipient_email' => 'empfaenger@beispiel.test',
            'kind' => 'task_assigned',
            'label' => 'Aufgabe zugewiesen',
            'title' => 'Angebot nachfassen',
            'color' => 'blue',
            'origin' => 'peppermint-manager',
            'origin_id' => 7,
        ]);

        expect($m)->not->toBeNull()
            ->and($m->field('title'))->toBe('Angebot nachfassen');
    });
});
