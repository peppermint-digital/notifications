<?php

namespace Peppermint\Notifications\Inbox;

use Illuminate\Database\Eloquent\Model;
use Peppermint\Inbox\Feed\Eintrag;
use Peppermint\Inbox\Quellen\AbhakbareQuelle;
use Peppermint\Inbox\Quellen\ZaehlbareQuelle;
use Peppermint\Notifications\Feed\NotificationActions;
use Peppermint\Notifications\Feed\NotificationFeed;

/**
 * Die Benachrichtigungen dieses Systems als Inbox-Art.
 *
 * ## Warum das hier liegt und nicht im Inbox-Paket
 *
 * Die Inbox weiss nicht, was eine Benachrichtigung ist — sie kennt nur
 * Eintraege mit einer Art. Umgekehrt weiss dieses Paket sehr genau, wie
 * eine Meldung aussieht. Der Uebersetzer gehoert also hierher; die
 * Abhaengigkeit zeigt in eine Richtung.
 *
 * ## Bewusst der LOKALE Feed
 *
 * {@see NotificationFeed}, nicht {@see \Peppermint\Notifications\Feed\GesamtFeed}.
 * Der GesamtFeed holt selbst bei den anderen Systemen — und die Inbox tut
 * das auch. Zusammen kaeme jede fremde Meldung doppelt an, einmal ueber
 * jeden Weg, und die Zaehler stuenden auf dem Doppelten.
 *
 * Eine Quelle liefert ausschliesslich, was in DIESEM System steht. Das
 * Zusammenlegen ist Sache des Sammlers.
 *
 * ## Abhaken und Zaehlen (AI Brain #1096)
 *
 * Die Inbox weiss nicht, wie eine Meldung gelesen wird — dieses Paket schon.
 * Deshalb liegt beides hier: {@see AbhakbareQuelle} gibt das Abhaken an
 * {@see NotificationActions} weiter, {@see ZaehlbareQuelle} nennt den
 * Bestand.
 *
 * Der Bestand ist der Grund, warum es diesen zweiten Vertrag gibt: Am
 * 26.10.2026 standen 3953 ungelesene Meldungen hinter 50 gezeigten Zeilen,
 * und nichts erklaerte die Luecke. Wer einzeln abarbeitet, sieht die Zahl
 * kaum sinken und haelt die Seite fuer kaputt.
 */
class MeldungsQuelle implements AbhakbareQuelle, ZaehlbareQuelle
{
    /** Unter welcher Art die Meldungen stehen. */
    public const ART = 'notification';

    public function __construct(
        private readonly NotificationFeed $feed,
        private readonly NotificationActions $aktionen,
    ) {}

    public function art(): string
    {
        return self::ART;
    }

    /**
     * Diese Meldungen dieser Person auf gelesen setzen.
     *
     * Nichts Eigenes, nur der Durchgang zu {@see NotificationActions}: Dort
     * sitzt der Empfaenger-Filter, der die Berechtigung IST, und dort wird
     * nur Ungelesenes angefasst — sonst setzt ein zweiter Klick den
     * Lesezeitpunkt neu, und der Verlauf behauptet, die Meldung sei eben
     * erst gelesen worden.
     *
     * Mehrere Kennungen, weil eine Zeile oft mehrere Datensaetze buendelt.
     * Eine Aktion, die nur einen trifft, laesst die Zeile stehen und den
     * Zaehler falsch.
     */
    public function alsGelesen(Model $person, array $ids): int
    {
        return $this->aktionen->markRead($person, $ids);
    }

    public function allesAlsGelesen(Model $person): int
    {
        return $this->aktionen->markAllRead($person);
    }

    /**
     * Wie viele ungelesene Meldungen diese Person hier insgesamt hat.
     *
     * Der LOKALE Feed, wie bei {@see eintraege()}: Der GesamtFeed holt
     * selbst bei den anderen Systemen, und die Inbox tut das auch — zusammen
     * stuende der Bestand auf dem Doppelten.
     */
    public function ungelesenGesamt(Model $person): int
    {
        return $this->feed->unreadCount($person);
    }

    public function eintraege(Model $person, int $grenze): array
    {
        $zeilen = array_slice($this->feed->for($person)['items'] ?? [], 0, $grenze);

        return array_map($this->uebersetzen(...), $zeilen);
    }

    /**
     * Eine Feed-Zeile in einen Inbox-Eintrag.
     *
     * Was die Inbox nicht kennt, reist in `meta` mit und steht als
     * Unterzeile da: die Beschriftung der Art, der Ausloeser, wie viele
     * Einzelmeldungen gebuendelt wurden.
     *
     * @param  array<string, mixed>  $zeile
     */
    private function uebersetzen(array $zeile): Eintrag
    {
        $gebuendelt = (int) ($zeile['bundle_count'] ?? 1);

        return new Eintrag(
            id: (string) ($zeile['id'] ?? ''),
            art: self::ART,
            titel: (string) ($zeile['title'] ?? ''),
            text: isset($zeile['message']) ? (string) $zeile['message'] : null,
            link: isset($zeile['link']) ? (string) $zeile['link'] : null,
            symbol: (string) ($zeile['icon'] ?? 'bell'),
            farbe: (string) ($zeile['color'] ?? 'gray'),
            zeitpunkt: isset($zeile['created_at']) ? (string) $zeile['created_at'] : null,
            ungelesen: ! ($zeile['is_read'] ?? false),
            meta: array_filter([
                'type_label' => $zeile['type_label'] ?? null,
                // Nur wo er etwas sagt: Eine Zeile hinter EINER Meldung
                // braucht kein „1×".
                'bundle' => $gebuendelt > 1 ? $gebuendelt.'×' : null,
            ], static fn ($wert): bool => $wert !== null && $wert !== ''),
        );
    }
}
