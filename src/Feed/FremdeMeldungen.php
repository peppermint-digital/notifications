<?php

namespace Peppermint\Notifications\Feed;

use Illuminate\Support\Facades\Log;

/**
 * Die Meldungen der ANDEREN Systeme — geholt, nicht gehalten.
 *
 * ## Warum geholt
 *
 * Die erste Fassung schob jede Meldung als Zeile in jedes Produkt. Das
 * widerspricht dem Prinzip, nach dem die uebrigen geteilten Systeme gebaut
 * sind: Es gibt eine Datenquelle, und niemand haelt Kopien. Der Kalender
 * macht es vor — {@see \App\Calendar\FremdeKalenderQuellen} im Manager
 * fragt beim Anzeigen und schreibt nichts.
 *
 * Der Unterschied ist nicht akademisch. Eine Kopie hat ihren eigenen
 * Lesestand, ihre eigene Loeschung und ihr eigenes Altern: Wer drueben eine
 * Meldung als gelesen markiert, aendert hier nichts, und nach einem Monat
 * weiss niemand mehr, welche der vier Zeilen die richtige ist.
 *
 * ## Fehlschlaege
 *
 * Ein System, das nicht antwortet, FEHLT in der Liste — es bringt die Seite
 * nicht zu Fall. Gemeldet wird es trotzdem: Eine still verkuerzte Liste
 * sieht aus wie „da ist nichts", und das ist die gefaehrlichere Aussage.
 */
class FremdeMeldungen
{
    public const CAPABILITY = 'notifications.list';

    /**
     * Alles, was die anderen Systeme fuer diese Person fuehren.
     *
     * @return array{items: array<int, array<string, mixed>>, unreachable: array<int, string>}
     */
    public function holen(?string $adresse, int $grenze = 100): array
    {
        $bruecke = 'Peppermint\\AiBrainBridge\\Facades\\AiBrain';

        if ($adresse === null || ! class_exists($bruecke)) {
            // Ohne Bruecke gibt es keine anderen Systeme — der Einzelbetrieb
            // ist kein Fehlerfall, sondern der zweite zugesicherte Zustand.
            return ['items' => [], 'unreachable' => []];
        }

        $zeilen = [];
        $stumm = [];

        foreach ($this->anbieter($bruecke) as $anbieter) {
            $slug = (string) ($anbieter['slug'] ?? '');

            if ($slug === '') {
                continue;
            }

            try {
                // MIT Slug: Hier ist ausdruecklich EIN System gemeint. Ohne
                // ihn suchte Brain sich einen Anbieter aus, und bei vier
                // Systemen waere das drei Mal der falsche.
                $antwort = $bruecke::gateway(self::CAPABILITY, ['limit' => $grenze], $slug, $adresse);

                foreach ($this->auspacken($antwort) as $zeile) {
                    // Die Herkunft setzt der AUFRUFER, nicht das befragte
                    // System: Es kennt seinen eigenen Slug im Verbund nicht
                    // zwangslaeufig, und eine Zeile ohne Herkunft fiele in
                    // der Ansicht unter „von hier".
                    $zeile['source'] = $anbieter['key'] ?? $slug;
                    $zeile['source_label'] = $anbieter['label'] ?? $slug;

                    $zeilen[] = $zeile;
                }
            } catch (\Throwable $fehler) {
                $stumm[] = (string) ($anbieter['label'] ?? $slug);

                Log::warning('Meldungen eines Systems nicht erreichbar.', [
                    'system' => $slug,
                    'error' => $fehler->getMessage(),
                ]);
            }
        }

        return ['items' => $zeilen, 'unreachable' => $stumm];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function anbieter(string $bruecke): array
    {
        try {
            return $bruecke::providers(self::CAPABILITY);
        } catch (\Throwable $fehler) {
            // Kein Verzeichnis, keine fremden Meldungen — aber die eigenen
            // stehen trotzdem da.
            Log::warning('Verzeichnis der Meldungs-Anbieter nicht erreichbar.', [
                'error' => $fehler->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Die Zeilen aus einer Gateway-Antwort schaelen.
     *
     * Der Gateway reicht durch, was das Werkzeug drueben geantwortet hat —
     * und MCP-Antworten tragen ihren Inhalt je nach Fassung entweder direkt
     * oder in `content`/`structuredContent`. Hier alle Formen abzufangen ist
     * nicht Unentschlossenheit, sondern die Lehre aus dem Kalender: Eine
     * neue SDK-Fassung aendert die Huelle, nicht den Inhalt.
     *
     * @param  array<string, mixed>  $antwort
     * @return array<int, array<string, mixed>>
     */
    private function auspacken(array $antwort): array
    {
        foreach ([$antwort, $antwort['structuredContent'] ?? null, $antwort['content'] ?? null, $antwort['result'] ?? null] as $huelle) {
            if (is_array($huelle) && isset($huelle['items']) && is_array($huelle['items'])) {
                return array_values(array_filter($huelle['items'], 'is_array'));
            }
        }

        return [];
    }
}
