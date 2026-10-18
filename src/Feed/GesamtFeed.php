<?php

namespace Peppermint\Notifications\Feed;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Der Posteingang über alle Systeme — eigene Zeilen plus geholte.
 *
 * ## Warum geholt und nicht gehalten
 *
 * Die erste Fassung kopierte jede Meldung in jedes Produkt. Das widerspricht
 * dem Prinzip, nach dem die übrigen geteilten Systeme gebaut sind: Es gibt
 * eine Datenquelle, und niemand hält Kopien. Eine Kopie hätte ihren eigenen
 * Lesestand, ihre eigene Löschung und ihr eigenes Altern — nach einem Monat
 * wüsste niemand mehr, welche der vier Zeilen die richtige ist.
 *
 * ## Warum die fremden Zeilen zwischengespeichert werden
 *
 * Ein Abruf geht über das Netz an jedes angebundene System. Ohne
 * Zwischenspeicher zahlte jede Seitenansicht diesen Preis — und das Abzeichen
 * in der Kopfzeile hängt an JEDER Seite.
 *
 * Die Dauer ist bewusst kurz: Eine Minute ist weniger, als zwischen dem
 * Entstehen einer Meldung und dem Hinsehen ohnehin vergeht, und sie deckt
 * genau den Fall ab, der sonst wehtut — zehn Seitenwechsel in einer Minute.
 *
 * ## Die eigenen Zeilen werden NICHT zwischengespeichert
 *
 * Sie kosten eine Abfrage auf der eigenen Datenbank. Und wer gerade etwas als
 * gelesen markiert hat, erwartet, dass es verschwindet — ein Zwischenspeicher
 * machte daraus ein Rätsel.
 */
class GesamtFeed
{
    /** Wie lange fremde Zeilen gelten. */
    private const HALTBAR = 60;

    public function __construct(
        private readonly NotificationFeed $eigene,
        private readonly FremdeMeldungen $fremde,
    ) {}

    /**
     * @return array{items: array<int, array<string, mixed>>, unread_count: int, unreachable: array<int, string>}
     */
    public function for(Model $empfaenger, bool $frisch = false): array
    {
        $eigen = $this->eigene->for($empfaenger);

        $fremd = $this->fremdeZeilen($empfaenger, $frisch);

        $zeilen = array_merge($eigen['items'] ?? [], $fremd['items']);

        // Über alle Systeme nach Zeit sortieren. Ohne das stünden erst alle
        // eigenen und dann alle fremden — und die Liste wäre nach Herkunft
        // geordnet statt nach dem, was zuletzt passiert ist.
        usort($zeilen, static function (array $a, array $b): int {
            $za = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
            $zb = strtotime((string) ($b['created_at'] ?? '')) ?: 0;

            // Ungelesenes zuerst, darin das Neueste — wie im eigenen Feed.
            $ra = empty($a['is_read']) ? 1 : 0;
            $rb = empty($b['is_read']) ? 1 : 0;

            return $ra === $rb ? $zb <=> $za : $rb <=> $ra;
        });

        return [
            'items' => $this->gekapptJeHerkunft($zeilen),
            'unread_count' => (int) ($eigen['unread_count'] ?? 0) + $fremd['unread_count'],
            // Welche Systeme gerade nicht antworten. Die Ansicht sagt es —
            // eine still verkürzte Liste sieht aus wie „da ist nichts".
            'unreachable' => $fremd['unreachable'],
        ];
    }

    /**
     * Die Obergrenze gilt JE SYSTEM, nicht für die ganze Liste.
     *
     * ## Der Fall, der das aufgedeckt hat
     *
     * AI Brain hatte 3.991 ungelesene eigene Meldungen. Eine globale
     * Obergrenze von 50 bedeutete: Die 50 neuesten stammten alle von hier,
     * und die Zeilen aus CRM und Verwaltung fielen hinten heraus — bei einer
     * Liste, die ausdrücklich „alles aus allen Systemen" zeigen soll.
     *
     * Schlimmer noch war die Folge für den Herkunfts-Filter: Er arbeitet im
     * Browser auf dem, was angekommen ist. Wer auf „CRM" klickte, sah nichts
     * — obwohl es dort etwas gab. Ein Filter, der leere Ergebnisse liefert,
     * wo es Treffer gibt, ist schlimmer als kein Filter.
     *
     * Also: aus jedem System die neuesten `max_items`. Die Gesamtliste wird
     * dadurch länger (bei vier Systemen bis zum Vierfachen), aber jede
     * Herkunft ist vertreten — und darum geht es auf dieser Seite.
     *
     * @param  array<int, array<string, mixed>>  $zeilen
     * @return array<int, array<string, mixed>>
     */
    private function gekapptJeHerkunft(array $zeilen): array
    {
        $grenze = (int) config('notifications.feed.max_items', 50);

        $gezaehlt = [];
        $behalten = [];

        // Die Liste ist bereits sortiert — wer zuerst kommt, ist der
        // neuere. Deshalb reicht ein Durchlauf.
        foreach ($zeilen as $zeile) {
            $herkunft = (string) ($zeile['source'] ?? '');

            $gezaehlt[$herkunft] = ($gezaehlt[$herkunft] ?? 0) + 1;

            if ($gezaehlt[$herkunft] <= $grenze) {
                $behalten[] = $zeile;
            }
        }

        return $behalten;
    }

    /**
     * Nur die Zahl — für das Abzeichen in der Kopfzeile.
     *
     * Hängt an jeder Seite, deshalb ohne die Aufbereitung der ganzen Liste
     * und mit demselben Zwischenspeicher.
     */
    public function unreadCount(Model $empfaenger): int
    {
        return $this->eigene->unreadCount($empfaenger)
            + $this->fremdeZeilen($empfaenger)['unread_count'];
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, unread_count: int, unreachable: array<int, string>}
     */
    private function fremdeZeilen(Model $empfaenger, bool $frisch = false): array
    {
        $adresse = $empfaenger->getAttribute('email');

        if (! is_string($adresse) || trim($adresse) === '') {
            return ['items' => [], 'unread_count' => 0, 'unreachable' => []];
        }

        $schluessel = 'notifications.fremde.'.sha1($adresse);

        if ($frisch) {
            Cache::forget($schluessel);
        }

        return Cache::remember($schluessel, self::HALTBAR, function () use ($adresse): array {
            $geholt = $this->fremde->holen($adresse);

            $ungelesen = 0;

            foreach ($geholt['items'] as $zeile) {
                if (empty($zeile['is_read'])) {
                    $ungelesen++;
                }
            }

            return [
                'items' => $geholt['items'],
                'unread_count' => $ungelesen,
                'unreachable' => $geholt['unreachable'],
            ];
        });
    }
}
