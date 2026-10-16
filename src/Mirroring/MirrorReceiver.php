<?php

namespace Peppermint\Notifications\Mirroring;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Peppermint\Notifications\Models\Notification;

/**
 * Nimmt eine gespiegelte Meldung entgegen und legt sie lokal an.
 *
 * Die Gegenseite zu {@see BrainMirror}. Was hier ankommt, stammt aus einem
 * anderen Produkt und soll in diesem genauso aussehen wie eine eigene
 * Meldung — mit einem Unterschied: `source` nennt die Herkunft, damit der
 * Verweis nachvollziehbar bleibt, der ins fremde System führt.
 *
 * ## Zweimal fail-closed, und das ist hier richtig
 *
 * Anders als beim Senden wird hier NICHT großzügig verfahren:
 *
 * - **Kein Empfänger, keine Zeile.** Wer die Adresse nicht kennt, legt keine
 *   Meldung „für irgendwen" an. Eine Zeile ohne richtigen Empfänger zählt im
 *   Abzeichen eines Fremden mit.
 * - **Schon da, nichts tun.** Der Komm-Layer liefert idempotent, aber ein
 *   Wiederholungsversuch nach einer Zeitüberschreitung kann durchkommen.
 *   Erkannt wird das am Spiegel-Schlüssel in `data`.
 */
class MirrorReceiver
{
    /**
     * Legt die gespiegelte Meldung an — oder gibt `null` zurück.
     *
     * @param  array<string, mixed>  $payload
     */
    public function empfangen(array $payload): ?Notification
    {
        $nutzlast = MirrorPayload::fromArray($payload);

        if (! $nutzlast->istVollstaendig()) {
            Log::warning('Gespiegelte Benachrichtigung verworfen: unvollstaendig.', [
                'origin' => $nutzlast->origin,
                'kind' => $nutzlast->kind,
            ]);

            return null;
        }

        $empfaenger = $this->empfaenger($nutzlast->recipientEmail);

        if ($empfaenger === null) {
            // Kein Fehler: Nicht jeder Mensch hat in jedem Produkt ein Konto.
            // Eine Aufgabenzuweisung im Manager erreicht jemanden, den das
            // CRM nicht kennt — dort gibt es dann eben keine Meldung.
            //
            // Nur auf `debug`: Bei vier Produkten und geteilten Meldungen ist
            // das der Normalfall und würde jedes Protokoll fluten.
            Log::debug('Gespiegelte Benachrichtigung ohne Konto hier — uebersprungen.', [
                'origin' => $nutzlast->origin,
                'kind' => $nutzlast->kind,
            ]);

            return null;
        }

        $schluessel = $nutzlast->spiegelSchluessel();

        if ($this->schonDa($empfaenger, $schluessel)) {
            return null;
        }

        return Notification::create([
            Notification::column('user_id') => $empfaenger->getKey(),
            Notification::column('type') => $nutzlast->kind,
            Notification::column('title') => $nutzlast->title,
            Notification::column('message') => $nutzlast->message,
            Notification::column('icon') => $nutzlast->icon,
            Notification::column('color') => $nutzlast->color,
            Notification::column('link') => $nutzlast->link,
            Notification::column('data') => $nutzlast->data + [
                // Woher sie kam und welche Zeile dort gemeint ist. Beides
                // zusammen ist der Riegel gegen die doppelte Zustellung.
                'mirror_key' => $schluessel,
                'mirror_origin' => $nutzlast->origin,
            ],
        ]);
    }

    /**
     * Gibt es diesen Menschen hier?
     */
    private function empfaenger(string $adresse): ?Model
    {
        /** @var class-string<Model> $modell */
        $modell = config('notifications.user_model', 'App\\Models\\User');
        $spalte = (string) config('notifications.mirror.email_column', 'email');

        return $modell::query()->where($spalte, $adresse)->first();
    }

    /**
     * Steht sie schon da?
     *
     * Gesucht wird über `data`, nicht über Titel und Zeit: Zwei
     * Zuweisungen derselben Aufgabe an denselben Menschen innerhalb einer
     * Minute sind inhaltlich zwei Meldungen, eine doppelt gelieferte
     * Spiegelung ist eine.
     */
    private function schonDa(Model $empfaenger, string $schluessel): bool
    {
        return Notification::query()
            ->forUser($empfaenger->getKey())
            ->where(Notification::column('data'), 'like', '%'.$schluessel.'%')
            ->exists();
    }
}
