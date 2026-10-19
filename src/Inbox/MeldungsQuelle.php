<?php

namespace Peppermint\Notifications\Inbox;

use Illuminate\Database\Eloquent\Model;
use Peppermint\Inbox\Feed\Eintrag;
use Peppermint\Inbox\Quellen\InboxQuelle;
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
 */
class MeldungsQuelle implements InboxQuelle
{
    /** Unter welcher Art die Meldungen stehen. */
    public const ART = 'notification';

    public function __construct(private readonly NotificationFeed $feed) {}

    public function art(): string
    {
        return self::ART;
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
