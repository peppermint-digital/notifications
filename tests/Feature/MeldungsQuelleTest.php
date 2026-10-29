<?php

use Peppermint\Inbox\Quellen\AbhakbareQuelle;
use Peppermint\Inbox\Quellen\LoeschbareQuelle;
use Peppermint\Inbox\Quellen\ZaehlbareQuelle;
use Peppermint\Notifications\Delivery\Notifier;
use Peppermint\Notifications\Feed\NotificationActions;
use Peppermint\Notifications\Inbox\MeldungsQuelle;
use Peppermint\Notifications\Models\Notification;
use Peppermint\Notifications\Tests\Support\User;

/**
 * Die Meldungen dieses Systems als Inbox-Art — und als abhakbare Quelle.
 *
 * Die Inbox weiss nicht, wie eine Meldung gelesen wird; dieses Paket schon.
 * Deshalb liegt der Uebersetzer hier, und seit AI Brain #1096 auch das
 * Abhaken und das Zaehlen — seit #9069 zusaetzlich das Loeschen.
 */
beforeEach(function (): void {
    $this->notifier = app(Notifier::class);
    $this->quelle = app(MeldungsQuelle::class);
    $this->ich = User::create(['name' => 'Ich']);
    $this->andere = User::create(['name' => 'Jemand anders']);
});

function meldungsquelleMeine(int $anzahl = 1): array
{
    return collect(range(1, $anzahl))->map(fn (): int|string => test()->notifier->send(
        to: test()->ich, type: 'infra.unreachable', title: 'Server nicht erreichbar',
    )->getKey())->all();
}

it('meldet sich als abhakbar UND zaehlbar an', function (): void {
    // Die beiden Vertraege sind freiwillig: Das Verzeichnis fragt mit
    // `instanceof`, und was nicht antwortet, hat eben keinen Haken. Ohne
    // diese Pruefung faellt es niemandem auf, wenn einer wegfaellt — die
    // Ansicht zeichnet dann still keinen Knopf mehr.
    expect($this->quelle)->toBeInstanceOf(AbhakbareQuelle::class)
        ->and($this->quelle)->toBeInstanceOf(ZaehlbareQuelle::class)
        ->and($this->quelle->art())->toBe('notification');
});

it('hakt mehrere Meldungen auf einmal ab', function (): void {
    // Der Normalfall nach dem Buendeln: Hinter einer Zeile stecken mehrere
    // Datensaetze, und ein Klick muss sie alle treffen.
    $ids = meldungsquelleMeine(3);

    expect($this->quelle->alsGelesen($this->ich, $ids))->toBe(3)
        ->and(Notification::query()->forUser($this->ich->id)->unread()->count())->toBe(0);
});

it('fasst fremde Meldungen nicht an, auch wenn ihre Kennung mitkommt', function (): void {
    // Der Empfaenger-Filter IST die Berechtigung. Eine fremde Kennung laeuft
    // ins Leere, statt den ganzen Aufruf scheitern zu lassen — bei
    // gebuendelten Zeilen kann eine veraltete dabei sein, ohne dass jemand
    // etwas falsch macht.
    $meine = meldungsquelleMeine(1);
    $fremde = $this->notifier->send(to: $this->andere, type: 'mention', title: 'Fremd');

    expect($this->quelle->alsGelesen($this->ich, [...$meine, $fremde->getKey()]))->toBe(1)
        ->and($fremde->fresh()->isRead())->toBeFalse();
});

it('hakt mit „alle" nur die eigenen ab', function (): void {
    meldungsquelleMeine(4);
    $fremde = $this->notifier->send(to: $this->andere, type: 'mention', title: 'Fremd');

    expect($this->quelle->allesAlsGelesen($this->ich))->toBe(4)
        ->and($fremde->fresh()->isRead())->toBeFalse();
});

it('nennt den BESTAND an MELDUNGEN, nicht die Zahl der Zeilen', function (): void {
    /*
     * Der Grund, warum es `ZaehlbareQuelle` gibt (#1096): Am 26.10.2026
     * standen 3953 ungelesene Meldungen hinter 50 gezeigten Zeilen, und
     * nichts erklaerte die Luecke. Wer einzeln abarbeitet, sieht die Zahl
     * kaum sinken und haelt die Seite fuer kaputt.
     *
     * UND der Unterschied ist groesser als nur die Kappung: Sieben
     * gleichartige Meldungen werden zu EINER Zeile gebuendelt. Der Bestand
     * zaehlt Meldungen, die Liste zeigt Zeilen — hier 7 gegen 1.
     *
     * Das ist die richtige Richtung, weil „alle als gelesen" ebenfalls
     * Meldungen abhakt und nicht Zeilen: Die Zahl am Knopf nennt, was er
     * wirklich anfasst. Der Satz unter der Liste sagt deshalb „Zeilen von
     * N ungelesenen" und nicht „N von M".
     */
    meldungsquelleMeine(7);

    expect($this->quelle->ungelesenGesamt($this->ich))->toBe(7)
        ->and($this->quelle->eintraege($this->ich, 50))->toHaveCount(1);
});

it('zaehlt eine abgehakte Meldung nicht mehr mit', function (): void {
    $ids = meldungsquelleMeine(5);

    $this->quelle->alsGelesen($this->ich, array_slice($ids, 0, 2));

    expect($this->quelle->ungelesenGesamt($this->ich))->toBe(3);
});

/*
|--------------------------------------------------------------------------
| Loeschen (AI Brain #9069)
|--------------------------------------------------------------------------
|
| Die Voraussetzung dafuer, dass die alte Meldungsseite verschwinden kann:
| Sie war bis zum 29.10.2026 die einzige Stelle, an der sich Meldungen
| loeschen liessen.
|
*/

it('meldet sich als loeschbar an', function (): void {
    // Die Oberflaeche raet nicht — sie zeichnet den Knopf nur dort, wo der
    // Vertrag steht. Aufgaben und Belege setzen ihn bewusst NICHT um.
    expect(app(MeldungsQuelle::class))->toBeInstanceOf(LoeschbareQuelle::class);
});

it('loescht mehrere Meldungen auf einmal', function (): void {
    // Mehrere Kennungen, weil eine Zeile oft mehrere Datensaetze buendelt.
    $eine = app(Notifier::class)->send(to: $this->ich, type: 'task.assigned', title: 'Eine');
    $zwei = app(Notifier::class)->send(to: $this->ich, type: 'task.assigned', title: 'Zwei');

    $geloescht = app(MeldungsQuelle::class)->loeschen($this->ich, [$eine->getKey(), $zwei->getKey()]);

    expect($geloescht)->toBe(2)
        ->and(Notification::query()->count())->toBe(0);
});

it('fasst die Meldung eines anderen Menschen nicht an, auch mit ihrer Kennung', function (): void {
    // Die Einschraenkung auf die handelnde Person IST die Rechtepruefung.
    // Eine fremde Kennung laeuft ins Leere statt den Aufruf zu sprengen.
    $fremd = app(Notifier::class)->send(to: $this->andere, type: 'task.assigned', title: 'Fremd');

    $geloescht = app(MeldungsQuelle::class)->loeschen($this->ich, [$fremd->getKey()]);

    expect($geloescht)->toBe(0)
        ->and(Notification::query()->count())->toBe(1);
});

it('loescht mit „alles" AUCH das Gelesene', function (): void {
    // Der Knopf heisst „Alle meine Benachrichtigungen loeschen", nicht
    // „alle ungelesenen". Genau deshalb steht davor eine Rueckfrage mit
    // der Zahl.
    $gelesen = app(Notifier::class)->send(to: $this->ich, type: 'task.assigned', title: 'Gelesen');
    app(NotificationActions::class)->markRead($this->ich, [$gelesen->getKey()]);
    app(Notifier::class)->send(to: $this->ich, type: 'task.assigned', title: 'Offen');

    $geloescht = app(MeldungsQuelle::class)->allesLoeschen($this->ich);

    expect($geloescht)->toBe(2)
        ->and(Notification::query()->count())->toBe(0);
});

it('laesst mit „alles" die Meldungen anderer Menschen stehen', function (): void {
    app(Notifier::class)->send(to: $this->andere, type: 'task.assigned', title: 'Fremd');
    app(Notifier::class)->send(to: $this->ich, type: 'task.assigned', title: 'Meins');

    app(MeldungsQuelle::class)->allesLoeschen($this->ich);

    expect(Notification::query()->count())->toBe(1);
});
