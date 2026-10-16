<?php

namespace Peppermint\Notifications\Feed;

/**
 * Wie die Systeme heissen, aus denen Meldungen kommen koennen.
 *
 * ## Warum das eine eigene Klasse ist und nicht einfach `config()`
 *
 * Zwei Dinge, die an jeder Aufrufstelle sonst einzeln richtig zu machen
 * waeren: Der Name des eigenen Systems faellt auf `app.name` zurueck, und die
 * Antwort hat immer dieselbe Form. Vier Produkte, die das je selbst
 * zusammensetzen, setzen es vier Mal leicht anders zusammen.
 */
class SourceLabels
{
    /**
     * @return array{local: string, sources: array<string, string>}
     */
    public static function all(): array
    {
        return [
            'local' => self::local(),
            'sources' => self::sources(),
        ];
    }

    /**
     * Der Name DIESES Systems.
     *
     * Er steht nicht an den eigenen Zeilen — die tragen keine Herkunft. Er
     * steht am Filter-Reiter, und dort ist „Hier" zwar richtig, aber weniger
     * hilfreich als der Name, den die Leute benutzen.
     */
    public static function local(): string
    {
        $eigen = config('notifications.labels.local');

        return is_string($eigen) && $eigen !== '' ? $eigen : (string) config('app.name', 'Hier');
    }

    /**
     * @return array<string, string>
     */
    public static function sources(): array
    {
        $liste = config('notifications.labels.sources', []);

        if (! is_array($liste)) {
            return [];
        }

        // Nur Zeichenketten: Ein verrutschter Eintrag soll die Ansicht nicht
        // mit „[object Object]" beschriften.
        return array_filter($liste, static fn ($wert, $key): bool => is_string($key) && is_string($wert), ARRAY_FILTER_USE_BOTH);
    }
}
