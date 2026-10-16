<?php

namespace Peppermint\Notifications\Mirroring;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Peppermint\Notifications\Events\NotificationCreated;
use Peppermint\Notifications\Models\Notification;

/**
 * Meldet eine neu entstandene Meldung an AI Brain — damit sie auch in den
 * anderen Produkten steht.
 *
 * ## Was hier NICHT passiert
 *
 * Die Meldung wird nicht hierhin verlagert. Sie steht bereits lokal, und das
 * bleibt so. Das ist der Unterschied zu `peppermint/contacts`, wo das Brain
 * die gültige Fassung hält: Eine Benachrichtigung ist nichts wert, wenn sie
 * beim Ausfall der Gegenstelle unsichtbar wird.
 *
 * Deshalb ist das hier ein ZUSATZ, kein Weg. Kommt er nicht durch, fehlt die
 * Meldung in den anderen Produkten — in diesem steht sie trotzdem.
 *
 * ## Fail-open, und zwar laut
 *
 * Jeder Fehler wird geschluckt und protokolliert. Eine Ausnahme hier würde
 * den Vorgang abbrechen, der die Meldung ausgelöst hat — die Zuweisung einer
 * Aufgabe scheiterte daran, dass ein anderes System nicht erreichbar ist. Das
 * wäre die Umkehrung dessen, was eine Benachrichtigung soll.
 *
 * Geschluckt heißt aber nicht verschwiegen: Jeder Fehlschlag steht im
 * Protokoll, mit Art und Empfänger. Ohne das sucht man später eine Meldung,
 * die nie losgeschickt wurde.
 */
class BrainMirror
{
    /** Der Ereignistyp auf der Komm-Layer-Schiene. */
    public const EVENT_TYPE = 'notification.created';

    /** Die Brücken-Fassade — nur vorhanden, wenn das Paket installiert ist. */
    private const BRIDGE = 'Peppermint\\AiBrainBridge\\Facades\\AiBrain';

    /**
     * Wurde die fehlende Bruecke schon gemeldet?
     *
     * Die Warnung gehoert einmal ins Protokoll, nicht einmal je Meldung. Das
     * AI Brain schreibt 243 am Tag; eine Warnung pro Stueck macht aus einem
     * Hinweis eine Flut, und die naechste echte Meldung geht darin unter.
     */
    private bool $brueckeGemeldet = false;

    public function handle(NotificationCreated $ereignis): void
    {
        // Reihenfolge mit Absicht: erst die billigen, stillen Fragen, dann
        // die, die etwas ins Protokoll schreiben.
        //
        // Umgekehrt — zuerst die Bruecke pruefen — warnte eine
        // Fehlkonfiguration fuer JEDE Meldung, auch fuer die, die ohnehin
        // niemand spiegeln wollte. Beim Schreiben der Tests aufgefallen.
        if (config('notifications.hub', 'local') !== 'brain') {
            return;
        }

        if (! $this->wirdGespiegelt((string) $ereignis->notification->field('type'))) {
            return;
        }

        $nutzlast = $this->nutzlast($ereignis->notification);

        if ($nutzlast === null) {
            return;
        }

        if (! $this->bruecke()) {
            return;
        }

        $bridge = self::BRIDGE;

        try {
            $bridge::emit(
                self::EVENT_TYPE,
                $nutzlast->toArray(),
                // Der Bezug macht das Ereignis für einen Menschen lesbar, der
                // später im Protokoll sucht.
                $nutzlast->spiegelSchluessel(),
            );
        } catch (\Throwable $e) {
            Log::warning('Benachrichtigung nicht an AI Brain gemeldet.', [
                'kind' => $nutzlast->kind,
                'recipient' => $nutzlast->recipientEmail,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ist die Brücke da?
     *
     * `hub: brain` ohne das Brücken-Paket ist eine Fehlkonfiguration, kein
     * Randfall: Jemand hält die Spiegelung für aktiv, während nichts
     * gespiegelt wird. Deshalb laut — aber nur EINMAL je Prozess.
     */
    private function bruecke(): bool
    {
        if (class_exists(self::BRIDGE)) {
            return true;
        }

        if (! $this->brueckeGemeldet) {
            $this->brueckeGemeldet = true;

            Log::warning(
                'notifications.hub steht auf "brain", aber peppermint/ai-brain-bridge ist nicht installiert. '
                .'Es wird NICHTS gespiegelt — wer die Meldungen in den anderen Produkten erwartet, wartet vergebens.'
            );
        }

        return false;
    }

    /**
     * Die Nutzlast — oder `null`, wenn diese Meldung nicht gespiegelt wird.
     */
    private function nutzlast(Notification $meldung): ?MirrorPayload
    {
        $art = (string) $meldung->field('type');
        $adresse = $this->adresse($meldung);

        if ($adresse === null) {
            // Ohne Adresse kann kein anderes Produkt den Menschen finden.
            // Das ist kein Fehler dieser Meldung, sondern eine Lücke im
            // Nutzer-Datensatz — und sie gehört ins Protokoll, nicht in die
            // Stille.
            Log::warning('Benachrichtigung nicht gespiegelt: Empfaenger ohne E-Mail-Adresse.', [
                'kind' => $art,
                'user_id' => $meldung->field('user_id'),
            ]);

            return null;
        }

        $nutzlast = MirrorPayload::fromNotification(
            meldung: $meldung,
            recipientEmail: $adresse,
            origin: (string) config('notifications.mirror.origin', config('app.name')),
            baseUrl: (string) config('app.url'),
        );

        return $nutzlast->istVollstaendig() ? $nutzlast : null;
    }

    /**
     * Gehört diese Art überhaupt in die anderen Produkte?
     *
     * Die Voreinstellung ist NEIN. „Neue E-Mail in Konto 3" hat im CRM nichts
     * verloren, und eine Infrastruktur-Meldung aus dem Brain erst recht
     * nicht — das Brain allein schreibt 243 davon am Tag.
     *
     * Gespiegelt wird nur, was ausdrücklich angemeldet ist:
     *
     *     'mirror' => ['types' => ['task.assigned', 'mention']],
     *
     * Ein `['*']` spiegelt alles. Das ist erlaubt, aber selten richtig.
     */
    private function wirdGespiegelt(string $art): bool
    {
        $angemeldet = (array) config('notifications.mirror.types', []);

        return in_array('*', $angemeldet, true) || in_array($art, $angemeldet, true);
    }

    /**
     * Die E-Mail-Adresse des Empfängers.
     */
    private function adresse(Notification $meldung): ?string
    {
        $empfaenger = $meldung->user;

        if (! $empfaenger instanceof Model) {
            return null;
        }

        $adresse = $empfaenger->getAttribute(config('notifications.mirror.email_column', 'email'));

        return is_string($adresse) && trim($adresse) !== '' ? $adresse : null;
    }
}
