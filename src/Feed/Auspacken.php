<?php

namespace Peppermint\Notifications\Feed;

use Peppermint\AiBrainBridge\Verbund\Huelle;

/**
 * Die Antwort von `list-notifications-tool` auspacken.
 *
 * Die Huellen-Logik steht in {@see Huelle} und wird mit dem Inbox-Paket
 * geteilt: Sie ist fuer jedes Werkzeug am Komm-Layer dieselbe, und eine
 * zweite Kopie veraltet still — wer die Huelle nicht findet, bekommt eine
 * LEERE Liste und keinen Fehler.
 */
final class Auspacken
{
    /**
     * @param  array<string, mixed>  $antwort
     * @return array{items: array<int, array<string, mixed>>, unread_count: int}
     */
    public static function antwort(array $antwort): array
    {
        $huelle = Huelle::schaelen($antwort);

        return [
            'items' => Huelle::zeilen($huelle),
            'unread_count' => (int) ($huelle['unread_count'] ?? 0),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function zeilen(array $antwort): array
    {
        return self::antwort($antwort)['items'];
    }
}
