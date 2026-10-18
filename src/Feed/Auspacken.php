<?php

namespace Peppermint\Notifications\Feed;

/**
 * Die Zeilen aus einer Gateway-Antwort schaelen.
 *
 * MCP-Antworten tragen ihren Inhalt je nach Fassung direkt, in `content`
 * oder in `structuredContent`. Alle Formen abzufangen ist nicht
 * Unentschlossenheit, sondern die Lehre aus dem Kalender: Eine neue
 * SDK-Fassung aendert die Huelle, nicht den Inhalt — und ein Aufrufer, der
 * nur eine kennt, bekommt beim Update still eine leere Liste.
 */
class Auspacken
{
    /**
     * Zeilen UND die Zahl der Ungelesenen, wie das System sie selbst zaehlt.
     *
     * @param  array<string, mixed>  $antwort
     * @return array{items: array<int, array<string, mixed>>, unread_count: int}
     */
    public static function antwort(array $antwort): array
    {
        $huelle = self::huelle($antwort);

        return [
            'items' => array_values(array_filter($huelle['items'] ?? [], 'is_array')),
            'unread_count' => (int) ($huelle['unread_count'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $antwort
     * @return array<int, array<string, mixed>>
     */
    public static function zeilen(array $antwort): array
    {
        return self::antwort($antwort)['items'];
    }

    /**
     * Die Huelle finden, in der der Inhalt steckt.
     *
     * @param  array<string, mixed>  $antwort
     * @return array<string, mixed>
     */
    private static function huelle(array $antwort): array
    {
        $huellen = [
            $antwort,
            $antwort['data'] ?? null,
            $antwort['structuredContent'] ?? null,
            $antwort['content'] ?? null,
            $antwort['result'] ?? null,
        ];

        foreach ($huellen as $huelle) {
            if (is_array($huelle) && isset($huelle['items']) && is_array($huelle['items'])) {
                return $huelle;
            }
        }

        return [];
    }
}
