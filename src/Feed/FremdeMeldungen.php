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
 * macht es vor — `FremdeKalenderQuellen` fragt beim Anzeigen und schreibt
 * nichts lokal.
 *
 * Der Unterschied ist nicht akademisch. Eine Kopie hat ihren eigenen
 * Lesestand, ihre eigene Loeschung und ihr eigenes Altern: Wer drueben eine
 * Meldung als gelesen markiert, aendert hier nichts, und nach einem Monat
 * weiss niemand mehr, welche der vier Zeilen die richtige ist.
 *
 * ## Woher gefragt wird, entscheidet die {@see RemoteSource}
 *
 * Ein Produkt fragt ueber die Bruecke, AI Brain ueber seinen eigenen
 * Gateway. Diese Klasse kennt den Unterschied nicht — sie fuehrt nur
 * zusammen und faengt Fehlschlaege ab.
 *
 * ## Fehlschlaege
 *
 * Ein System, das nicht antwortet, FEHLT in der Liste — es bringt die Seite
 * nicht zu Fall. Gemeldet wird es trotzdem, und zwar bis nach oben in die
 * Ansicht: Eine still verkuerzte Liste sieht aus wie „da ist nichts", und
 * das ist die gefaehrlichere Aussage, weil niemand nachfragt.
 */
class FremdeMeldungen
{
    public const CAPABILITY = 'notifications.list';

    public function __construct(private readonly RemoteSource $quelle) {}

    /**
     * Alles, was die anderen Systeme fuer diese Person fuehren.
     *
     * @return array{items: array<int, array<string, mixed>>, unread_count: int, unreachable: array<int, string>}
     */
    public function holen(?string $adresse, int $grenze = 100): array
    {
        if ($adresse === null || trim($adresse) === '') {
            // Die E-Mail ist der einzige produktuebergreifende Schluessel.
            // Ohne sie koennte ein Aufruf nur „alle" meinen — ueber
            // Systemgrenzen hinweg waere das ein Datenleck.
            return ['items' => [], 'unread_count' => 0, 'unreachable' => []];
        }

        $zeilen = [];
        $stumm = [];
        $ungelesen = 0;

        foreach ($this->anbieter() as $anbieter) {
            $slug = (string) ($anbieter['slug'] ?? '');

            if ($slug === '') {
                continue;
            }

            try {
                $antwort = $this->quelle->holen($slug, $adresse, $grenze);

                // Die Zahl kommt vom System selbst, nicht aus der Laenge der
                // Liste: Die Zeilen sind gebuendelt und gekappt. Wer sie
                // zaehlt, bekommt in jedem System eine andere Summe fuer
                // dieselbe Sache.
                $ungelesen += (int) ($antwort['unread_count'] ?? 0);

                foreach ($antwort['items'] ?? [] as $zeile) {
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

        return ['items' => $zeilen, 'unread_count' => $ungelesen, 'unreachable' => $stumm];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function anbieter(): array
    {
        try {
            return $this->quelle->anbieter();
        } catch (\Throwable $fehler) {
            // Kein Verzeichnis, keine fremden Meldungen — aber die eigenen
            // stehen trotzdem da.
            Log::warning('Verzeichnis der Meldungs-Anbieter nicht erreichbar.', [
                'error' => $fehler->getMessage(),
            ]);

            return [];
        }
    }
}
