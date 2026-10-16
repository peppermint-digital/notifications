<?php

namespace Peppermint\Notifications\Delivery;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Peppermint\Notifications\Events\NotificationCreated;
use Peppermint\Notifications\Events\NotificationRolled;
use Peppermint\Notifications\Models\Notification;
use Peppermint\Notifications\Preferences\Channel;
use Peppermint\Notifications\Preferences\PreferenceResolver;

/**
 * Die eine Stelle, an der eine Meldung entsteht.
 *
 * Im Manager steht diese Mechanik heute 28 Mal nebeneinander: Jede der 28
 * Melde-Methoden prueft selbst, ob der Ausloeser der Empfaenger ist, baut
 * selbst ihren Datensatz und schickt selbst den Push. Zwei davon — die
 * Chat-Nachricht und die Mail-Zusammenfassung — koennen zusaetzlich rollen,
 * und beide haben dafuer ihre eigene Fassung.
 *
 * Hier ist das zweimal: {@see send()} und {@see rolling()}.
 *
 * ## Was dieser Dienst NICHT tut
 *
 * Er sucht keine Empfaenger. „Alle Projektmitglieder ausser dem Ausloeser" ist
 * eine Frage an produkteigene Beziehungen; der Kern nimmt einen Empfaenger an,
 * er ermittelt ihn nicht.
 *
 * Er pusht auch nicht selbst. Er feuert ein Ereignis, und wer pushen oder
 * spiegeln will, haengt sich daran. Sonst muesste dieser Dienst wissen, ob
 * gerade AI Brain die Schluessel haelt oder das Produkt — und das ist die
 * Frage, die ihn nichts angeht.
 */
class Notifier
{
    /**
     * Eine Meldung zustellen.
     *
     * Gibt `null` zurueck, wenn der Einmal-Riegel gegriffen hat. Bewusst keine
     * Ausnahme: „niemanden ueber die eigene Handlung benachrichtigen" ist der
     * Normalfall, kein Fehler.
     *
     * @param  array<string, mixed>  $data  Was nur dieses Produkt angeht.
     */
    public function send(
        Model|int|string $to,
        string $type,
        string $title,
        ?string $message = null,
        ?Model $subject = null,
        Model|int|string|null $triggeredBy = null,
        ?string $link = null,
        ?string $icon = null,
        array $data = [],
    ): ?Notification {
        $empfaenger = $this->id($to);
        $ausloeser = $this->id($triggeredBy);

        if ($this->istEigeneHandlung($empfaenger, $ausloeser)) {
            return null;
        }

        if (! $this->willSehen($empfaenger, $type)) {
            return null;
        }

        $meldung = Notification::create($this->attribute([
            'user_id' => $empfaenger,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'icon' => $icon,
            'link' => $link,
            'notifiable_type' => $subject ? $subject->getMorphClass() : null,
            'notifiable_id' => $subject?->getKey(),
            'triggered_by' => $ausloeser,
            'data' => $data === [] ? null : $data,
        ]));

        Event::dispatch(new NotificationCreated($meldung));

        return $meldung;
    }

    /**
     * Eine rollende Sammelmeldung: aktualisieren statt eine zweite anlegen.
     *
     * Gibt es zu diesem Gegenstand schon eine UNGELESENE Meldung dieser Art
     * fuer diese Person, wird sie fortgeschrieben — Zaehler hoch, Text neu,
     * wieder nach oben sortiert. Erst wenn jemand gelesen hat, beginnt ein
     * neuer Zyklus.
     *
     * Dahinter steckt die Zahl, die dieses Paket ueberhaupt ausgeloest hat: Im
     * AI Brain sind 94 % der Meldungen ungelesen, weil „Server nicht
     * erreichbar: graceful-athens" 604 Mal einzeln untereinander steht.
     *
     * ## Der Zaehler steht in `data`, nicht im Text
     *
     * Der Manager liest ihn heute per regulaerem Ausdruck aus der Nachricht
     * zurueck (`/^(\d+) neue Nachrichten$/`). Das funktioniert genau so lange,
     * wie niemand den Wortlaut aendert — und es ist beim Uebersetzen des
     * Produkts strukturell nicht zu halten. Hier zaehlt `data.rolled_count`,
     * und der Text wird daraus gebaut.
     *
     * @param  Closure(int): string  $message  Bekommt die Gesamtzahl.
     * @param  array<string, mixed>  $data
     */
    public function rolling(
        Model|int|string $to,
        string $type,
        string $title,
        Closure $message,
        ?Model $subject = null,
        Model|int|string|null $triggeredBy = null,
        ?string $link = null,
        ?string $icon = null,
        int $increment = 1,
        array $data = [],
    ): ?Notification {
        $empfaenger = $this->id($to);
        $ausloeser = $this->id($triggeredBy);

        if ($this->istEigeneHandlung($empfaenger, $ausloeser)) {
            return null;
        }

        if (! $this->willSehen($empfaenger, $type)) {
            return null;
        }

        $bestehende = Notification::query()
            ->forUser($empfaenger)
            ->ofType($type)
            ->unread()
            ->about($subject?->getMorphClass(), $subject?->getKey())
            ->latest(Notification::column('id'))
            ->first();

        if ($bestehende === null) {
            $meldung = $this->send(
                to: $empfaenger,
                type: $type,
                title: $title,
                message: $message($increment),
                subject: $subject,
                triggeredBy: $ausloeser,
                link: $link,
                icon: $icon,
                data: $data + ['rolled_count' => $increment],
            );

            return $meldung;
        }

        $bisher = (int) (($bestehende->field('data')['rolled_count'] ?? 1));
        $gesamt = $bisher + $increment;

        $bestehende->forceFill($this->attribute([
            'title' => $title,
            'message' => $message($gesamt),
            'triggered_by' => $ausloeser,
            'data' => array_merge((array) $bestehende->field('data'), $data, ['rolled_count' => $gesamt]),
            // Wieder nach oben sortieren: Die Sache ist gerade eben passiert,
            // auch wenn die Zeile von vorhin stammt.
            'created_at' => now(),
            'updated_at' => now(),
        ]))->save();

        // Ein EIGENES Ereignis, nicht dasselbe wie beim Anlegen.
        //
        // Wer pusht, soll hier NICHT pushen: Der Browser hat die Meldung schon
        // gezeigt, und ein zweiter Ton fuer dieselbe Sache ist genau die Flut,
        // gegen die das Rollen gebaut ist. Wer trotzdem will, hoert mit.
        Event::dispatch(new NotificationRolled($bestehende, $gesamt));

        return $bestehende;
    }

    /**
     * Niemanden ueber die eigene Handlung benachrichtigen.
     *
     * Der Riegel steht im Manager 28 Mal einzeln da und fehlte an mindestens
     * einer Stelle, bis es jemandem auffiel. Hier steht er einmal.
     */
    /**
     * Will dieser Mensch Meldungen dieser Art ueberhaupt in der Anwendung sehen?
     *
     * Der Weg „in der Anwendung" ist hier der entscheidende, und zwar als
     * einziger: Push und E-Mail haengen an {@see NotificationCreated} und
     * entscheiden selbst. Steht die Glocke auf „aus", entsteht dagegen gar
     * keine Zeile — und dann gibt es auch nichts zu pushen.
     *
     * ## Warum nicht anlegen und beim Anzeigen filtern
     *
     * Das waere die vorsichtigere Variante: nichts verlieren, nur verbergen.
     * Sie waere hier aber die falsche. „Aus" heisst bei einem Posteingang
     * nicht „sammle es und zeig es mir nicht", sondern „ich will das nicht
     * haben". Eine Tabelle, die jahrelang mitschreibt, was niemand je sehen
     * wird, ist genau die Halde, gegen die dieses Paket gebaut wurde.
     *
     * Rueckwirkend gilt es nicht: Was schon dasteht, bleibt stehen.
     */
    private function willSehen(int|string|null $empfaenger, string $art): bool
    {
        if ($empfaenger === null) {
            return true;
        }

        return app(PreferenceResolver::class)->zustellen($empfaenger, $art, Channel::InApp);
    }

    private function istEigeneHandlung(int|string $empfaenger, int|string|null $ausloeser): bool
    {
        return $ausloeser !== null && (string) $ausloeser === (string) $empfaenger;
    }

    private function id(Model|int|string|null $wert): int|string|null
    {
        return $wert instanceof Model ? $wert->getKey() : $wert;
    }

    /**
     * Paketfelder auf die tatsaechlichen Spaltennamen umschreiben.
     *
     * @param  array<string, mixed>  $felder
     * @return array<string, mixed>
     */
    private function attribute(array $felder): array
    {
        $umgeschrieben = [];

        foreach ($felder as $name => $wert) {
            $umgeschrieben[Notification::column($name)] = $wert;
        }

        return $umgeschrieben;
    }
}
