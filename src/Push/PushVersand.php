<?php

namespace Peppermint\Notifications\Push;

use Illuminate\Support\Facades\Log;
use Peppermint\Notifications\Events\NotificationCreated;
use Peppermint\Notifications\Preferences\Channel;
use Peppermint\Notifications\Preferences\Level;
use Peppermint\Notifications\Preferences\PreferenceResolver;

/**
 * Entscheidet, ob eine neue Meldung gepusht wird, und stellt den Versand in
 * die Warteschlange.
 *
 * Gefragt wird die Einstellung der Person fuer den Weg Push — dieselbe
 * Matrix, die die Glocke und die E-Mail steuert. Gepusht wird nur bei
 * `instant`: Ein gesammelter Push waere keiner, `digest` bedeutet fuer
 * diesen Weg deshalb nichts und klingelt nicht.
 *
 * Fail-open: Dass der Versand nicht eingereiht werden kann, darf den Vorgang
 * nicht abbrechen, der die Meldung ausgeloest hat. Die Meldung steht in der
 * Glocke.
 */
class PushVersand
{
    public function __construct(
        private readonly PreferenceResolver $einstellungen,
        private readonly PushZusteller $zusteller,
    ) {}

    public function handle(NotificationCreated $ereignis): void
    {
        if (! PushBetrieb::lokal() || ! $this->zusteller->eingerichtet()) {
            return;
        }

        $meldung = $ereignis->notification;
        $person = $meldung->field('user_id');

        if ($person === null) {
            return;
        }

        try {
            if ($this->einstellungen->level($person, (string) $meldung->field('type'), Channel::Push) !== Level::Instant) {
                return;
            }

            SendePush::dispatch($meldung->getKey());
        } catch (\Throwable $fehler) {
            Log::warning('Push zu einer Benachrichtigung nicht eingereiht.', ['error' => $fehler->getMessage()]);
        }
    }
}
