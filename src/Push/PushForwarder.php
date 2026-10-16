<?php

namespace Peppermint\Notifications\Push;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Ein Abo an den Hub melden — oder zuruecknehmen.
 *
 * ## Warum das Abo zum Schluessel wandert
 *
 * Signieren kann nur, wer den privaten Teil des VAPID-Paares hat. Liegt der
 * in AI Brain, muss auch das Abo dort liegen — sonst haelt ein Produkt eine
 * Adresse, die es nicht bedienen kann.
 *
 * Das Produkt BEHAELT seine eigene Zeile trotzdem. Nicht aus Vorsicht,
 * sondern weil sie den Einzelbetrieb traegt: Faellt der Hub weg, steht dort
 * weiter, wer abonniert hat.
 *
 * ## Warum ueber die Ereignis-Schiene
 *
 * Dieselbe, auf der die Meldungen laufen: signiert, nachvollziehbar, mit
 * Wiederholung. Ein zweiter Weg zum selben Hub waere ein zweiter Weg, der
 * ausfallen und uebersehen werden kann.
 */
class PushForwarder
{
    public const EVENT_SUBSCRIBED = 'push.subscribed';

    public const EVENT_UNSUBSCRIBED = 'push.unsubscribed';

    /**
     * @param  array{endpoint: string, keys: array{p256dh: string, auth: string}}  $abo
     */
    public function subscribed(Model $user, array $abo, ?string $userAgent = null): bool
    {
        return $this->melden(self::EVENT_SUBSCRIBED, $user, [
            'endpoint' => $abo['endpoint'],
            'p256dh' => $abo['keys']['p256dh'] ?? '',
            'auth' => $abo['keys']['auth'] ?? '',
            'user_agent' => $userAgent,
        ]);
    }

    public function unsubscribed(Model $user, string $endpoint): bool
    {
        return $this->melden(self::EVENT_UNSUBSCRIBED, $user, ['endpoint' => $endpoint]);
    }

    /**
     * @param  array<string, mixed>  $nutzlast
     */
    private function melden(string $art, Model $user, array $nutzlast): bool
    {
        if (config('notifications.hub', 'local') !== 'brain') {
            return false;
        }

        $adresse = $user->getAttribute('email');

        if (! is_string($adresse) || trim($adresse) === '') {
            // Die E-Mail ist der einzige produktuebergreifende Schluessel.
            // Ohne sie findet der Hub den Menschen nicht.
            Log::warning('Push-Abo nicht an den Hub gemeldet: Empfaenger ohne E-Mail.', ['art' => $art]);

            return false;
        }

        $bruecke = 'Peppermint\\AiBrainBridge\\Facades\\AiBrain';

        if (! class_exists($bruecke)) {
            Log::warning('Push-Abo nicht an den Hub gemeldet: keine Bruecke installiert.', ['art' => $art]);

            return false;
        }

        try {
            return (bool) $bruecke::emit(
                type: $art,
                payload: $nutzlast + ['user_email' => $adresse],
                // Die Adresse des Abos ist seine Kennung — dieselbe zweimal
                // zu melden darf nicht zwei Zeilen ergeben.
                entityRef: 'push:'.sha1((string) $nutzlast['endpoint']),
            );
        } catch (\Throwable $fehler) {
            // Fail-open: Dass der Hub das Abo nicht erfaehrt, darf den
            // Menschen nicht am Abonnieren hindern. Die Zeile im Produkt
            // steht dann trotzdem.
            Log::warning('Push-Abo nicht an den Hub gemeldet.', [
                'art' => $art,
                'error' => $fehler->getMessage(),
            ]);

            return false;
        }
    }
}
