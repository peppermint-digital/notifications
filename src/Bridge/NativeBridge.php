<?php

namespace Peppermint\Notifications\Bridge;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Peppermint\Notifications\Delivery\Notifier;
use Peppermint\Notifications\Events\NotificationCreated;
use Peppermint\Notifications\Models\Notification;
use Peppermint\Notifications\Preferences\Channel;
use Peppermint\Notifications\Preferences\PreferenceResolver;

/**
 * Die Brücke für Produkte, die ihre Meldungen NICHT über den Notifier anlegen.
 *
 * ## Das Loch, das sie schliesst
 *
 * Alles, was dieses Paket über eine neue Meldung tut, hängt an einem einzigen
 * Ereignis: `NotificationCreated`. Es spiegelt zum Hub, es löst die
 * Sofort-Mail aus, und der Riegel aus der Einstellungsmatrix sitzt davor.
 *
 * Gefeuert wird es ausschliesslich vom {@see Notifier}. Die drei Produkte
 * benutzen den aber gar nicht — sie schreiben seit Jahren über
 * `$user->notify(new XyzNotification(...))`, also über Laravels
 * `DatabaseNotification`. Ihr Beobachter füllte bisher nur die Spalten.
 *
 * Ergebnis, gemessen am 18.10.2026: **null** Meldungen aus den Produkten
 * waren je beim Hub angekommen. Rund 188 pro Woche blieben liegen, und
 * niemandem fiel es auf, weil die Gegenrichtung (Brain → Produkte) tadellos
 * lief und beim Livetest auch nur die geprüft wurde.
 *
 * ## Warum nicht einfach den Notifier benutzen
 *
 * Weil das heisst, 28 gewachsene Melde-Methoden im Manager, 15 im CRM und
 * ein Dutzend in der Verwaltung umzuschreiben — jede mit eigener
 * Empfängerlogik, eigenen Vorlagen, eigenen Tests. Das ist eine eigene
 * Aufgabe, und bis sie getan ist, wäre die Spiegelung weiter kaputt.
 *
 * Diese Brücke hängt stattdessen am Beobachter, den jedes Produkt ohnehin
 * schon hat, und holt nach, was das Ereignis getan hätte.
 */
class NativeBridge
{
    public function __construct(
        private readonly PreferenceResolver $einstellungen,
    ) {}

    /**
     * VOR dem Anlegen: Will dieser Mensch Meldungen dieser Art überhaupt sehen?
     *
     * `false` bedeutet im Beobachter eines Produkts: `creating` gibt `false`
     * zurück und die Zeile entsteht nicht. Genau das tut der {@see Notifier}
     * an derselben Stelle — „aus" heisst bei einem Posteingang nicht
     * „sammle es und zeig es mir nicht".
     */
    public function willSehen(int|string|null $empfaenger, ?string $art): bool
    {
        if ($empfaenger === null || $art === null || $art === '') {
            // Ohne Empfänger oder ohne erkennbare Art lässt sich nichts
            // abschalten — also wird zugestellt. Lautlos zu verschlucken,
            // was man nicht zuordnen kann, wäre der schlechtere Ausgang.
            return true;
        }

        return $this->einstellungen->zustellen($empfaenger, $art, Channel::InApp);
    }

    /**
     * NACH dem Anlegen: nachholen, was `NotificationCreated` ausgelöst hätte.
     *
     * Nimmt den Schlüssel und nicht das Modell: Der Aufrufer hält eine
     * `DatabaseNotification` in der Hand, die Horcher erwarten die
     * {@see Notification} des Pakets. Beide liegen auf derselben Tabelle.
     */
    public function angelegt(mixed $schluessel): void
    {
        try {
            $meldung = Notification::query()->whereKey($schluessel)->first();

            if ($meldung === null) {
                return;
            }

            // Dasselbe Ereignis, das der Notifier feuern würde. Damit greift
            // ALLES, was daran hängt — heute Spiegelung und Sofort-Mail,
            // morgen, was dazukommt. Hier die einzelnen Horcher aufzuzählen
            // hiesse, diese Liste an einer zweiten Stelle zu pflegen.
            event(new NotificationCreated($meldung));
        } catch (\Throwable $fehler) {
            // Fail-open: Dass eine Meldung den Hub nicht erreicht, darf den
            // Vorgang nicht abbrechen, der sie ausgelöst hat.
            Log::warning('Eigene Meldung konnte nicht an die Bruecke gegeben werden.', [
                'id' => $schluessel,
                'error' => $fehler->getMessage(),
            ]);
        }
    }

    /**
     * Den Empfänger aus einer Laravel-Benachrichtigung lesen.
     *
     * In der Standardform ist `notifiable_id` der EMPFÄNGER — nicht der
     * Gegenstand. Produkte mit der Manager-Form führen stattdessen
     * `user_id`. Beides kommt vor, deshalb fragt die Brücke beides ab,
     * statt es dem Aufrufer zu überlassen.
     */
    public function empfaengerAus(Model $benachrichtigung): int|string|null
    {
        foreach ([Notification::column('user_id'), 'user_id', 'notifiable_id'] as $spalte) {
            $wert = $benachrichtigung->getAttribute($spalte);

            if ($wert !== null) {
                return $wert;
            }
        }

        return null;
    }
}
