<?php

namespace Peppermint\Notifications\Feed;

/**
 * Der Weg eines PRODUKTS: ueber die Bruecke zum Hub und weiter.
 *
 * Die Gegenseite — AI Brain selbst — bindet eine eigene Umsetzung, weil es
 * die Bruecke nicht hat. Siehe {@see RemoteSource}.
 */
class BridgeRemoteSource implements RemoteSource
{
    private const BRUECKE = 'Peppermint\\AiBrainBridge\\Facades\\AiBrain';

    public function anbieter(): array
    {
        if (! class_exists(self::BRUECKE)) {
            // Ohne Bruecke gibt es keine anderen Systeme. Der Einzelbetrieb
            // ist kein Fehlerfall, sondern der zweite zugesicherte Zustand.
            return [];
        }

        return (self::BRUECKE)::providers(FremdeMeldungen::CAPABILITY);
    }

    public function holen(string $slug, string $adresse, int $grenze): array
    {
        // MIT Slug: Hier ist ausdruecklich EIN System gemeint. Ohne ihn
        // suchte der Hub sich einen aus, und bei vier Systemen waere das
        // drei Mal der falsche.
        $antwort = (self::BRUECKE)::gateway(
            FremdeMeldungen::CAPABILITY,
            ['limit' => $grenze],
            $slug,
            $adresse,
        );

        return Auspacken::zeilen(is_array($antwort) ? $antwort : []);
    }
}
