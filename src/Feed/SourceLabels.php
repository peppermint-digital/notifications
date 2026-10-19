<?php

namespace Peppermint\Notifications\Feed;

use Peppermint\AiBrainBridge\Verbund\Systemnamen;

/**
 * Wie die Systeme in DIESEM Paket heissen.
 *
 * Nur die Zuordnung auf `config('notifications.labels')`; die Mechanik
 * steht in {@see Systemnamen} und wird mit dem Inbox-Paket geteilt.
 *
 * Zwei Listen bleiben es trotzdem — jedes Paket behaelt seine eigene
 * Konfiguration. Das ist Absicht: Ein Produkt, das nur eines von beiden
 * benutzt, soll nicht die Datei des anderen pflegen muessen.
 */
final class SourceLabels
{
    private const KONFIG = 'notifications';

    /** @return array{local: string, sources: array<string, string>} */
    public static function all(): array
    {
        return Systemnamen::alle(self::KONFIG);
    }

    public static function local(): string
    {
        return Systemnamen::eigenes(self::KONFIG);
    }

    /** @return array<string, string> */
    public static function sources(): array
    {
        return Systemnamen::fremde(self::KONFIG);
    }
}
