<?php

namespace Peppermint\Notifications\Push;

/**
 * Wer haelt die Push-Abos, und wer signiert?
 *
 * Drei Antworten, eine Stelle:
 *
 * - `hub`   — AI Brain haelt Abos und Schluessel. Das Produkt reicht ein Abo
 *             nur weiter und holt den oeffentlichen Schluessel dort.
 * - `local` — das Produkt haelt seine Abos in der eigenen Tabelle, hat sein
 *             eigenes VAPID-Paar und verschickt selbst.
 * - `none`  — kein Push. Der Abo-Endpunkt antwortet ehrlich, dass es keinen
 *             gibt, statt etwas anzunehmen, das niemand bedient.
 *
 * Ohne Angabe folgt der Weg dem Betriebsmodus: `hub = brain` heisst `hub`,
 * alles andere `none`. Das ist das Verhalten bis v0.31 und Absicht: Ein
 * Produkt, das das Paket nur anhebt, faengt nicht still an, Abos zu speichern
 * — AI Brain etwa steht auf `local` und hat seine eigene Abo-Tabelle.
 * `local` muss man ausdruecklich wollen.
 */
final class PushBetrieb
{
    public const HUB = 'hub';

    public const LOCAL = 'local';

    public const NONE = 'none';

    public static function modus(): string
    {
        $gesetzt = config('notifications.push.driver');

        if (in_array($gesetzt, [self::HUB, self::LOCAL, self::NONE], true)) {
            return $gesetzt;
        }

        return config('notifications.hub', 'local') === 'brain' ? self::HUB : self::NONE;
    }

    public static function lokal(): bool
    {
        return self::modus() === self::LOCAL;
    }

    public static function ueberHub(): bool
    {
        return self::modus() === self::HUB;
    }
}
