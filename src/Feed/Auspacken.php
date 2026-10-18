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
     * @param  array<string, mixed>  $antwort
     * @return array<int, array<string, mixed>>
     */
    public static function zeilen(array $antwort): array
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
                return array_values(array_filter($huelle['items'], 'is_array'));
            }
        }

        return [];
    }
}
