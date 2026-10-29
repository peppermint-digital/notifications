<?php

namespace Peppermint\Notifications\Feed;

use Peppermint\Notifications\Support\Bruecke;

/**
 * Der Weg eines PRODUKTS: ueber die Bruecke zum Hub und weiter.
 *
 * Die Gegenseite — AI Brain selbst — bindet eine eigene Umsetzung, weil es
 * die Bruecke nicht hat. Siehe {@see RemoteSource}.
 */
class BridgeRemoteSource implements RemoteSource
{
    private const BRUECKE = Bruecke::FACADE;

    /**
     * Der Hub selbst — er steht NICHT in der Anbieterliste.
     *
     * `providers()` zaehlt die angebundenen Produkte auf, nicht AI Brain.
     * Fuer einen Kalender ist das richtig; fuer Benachrichtigungen waere es
     * der groesste Ausfall von allen, denn der Hub fuehrt die Mehrzahl der
     * Meldungen. Deshalb steht er hier fest in der Liste.
     */
    private const HUB_SLUG = 'ai-brain';

    public function anbieter(): array
    {
        if (! Bruecke::verfuegbar()) {
            // Ohne Bruecke gibt es keine anderen Systeme. Der Einzelbetrieb
            // ist kein Fehlerfall, sondern der zweite zugesicherte Zustand.
            return [];
        }

        // Der Hub zuerst: Faellt eines der Produkte aus, soll wenigstens
        // die Hauptquelle dastehen.
        return array_merge([$this->hub()], (self::BRUECKE)::providers(FremdeMeldungen::CAPABILITY));
    }

    /**
     * Die Nabe als Anbieterzeile — von Hand, weil sie in keinem
     * Verzeichnis steht (siehe Klassenkopf).
     *
     * ## Warum `url` dazugehoert (#1151)
     *
     * Die uebrigen Anbieter bringen ihre Adresse aus dem Verzeichnis mit,
     * und {@see FremdeMeldungen} macht damit einen noch relativen Verweis
     * absolut. Fehlte sie hier, wuerde ausgerechnet der groesste
     * Beitraeger weiter relative Verweise liefern — und `/bugs/1122`
     * meint in diesem Produkt nichts.
     *
     * Die Adresse steht in der eigenen Konfiguration: Es ist die, mit der
     * dieses Produkt AI Brain ohnehin anspricht.
     *
     * @return array{slug: string, key: string, label: string, url: string|null}
     */
    private function hub(): array
    {
        $adresse = trim((string) config('ai-brain-bridge.base_url'));

        return [
            'slug' => self::HUB_SLUG,
            'key' => self::HUB_SLUG,
            'label' => 'AI Brain',
            'url' => $adresse === '' ? null : $adresse,
        ];
    }

    public function holen(string $slug, string $adresse, int $grenze): array
    {
        if ($slug === self::HUB_SLUG) {
            // Den Hub fragt man direkt, nicht ueber den Gateway: Der
            // vermittelt zwischen PRODUKTEN, und das Brain ist keines.
            return Auspacken::antwort(
                (self::BRUECKE)::call('list-notifications-tool', ['limit' => $grenze]),
            );
        }

        // MIT Slug: Hier ist ausdruecklich EIN System gemeint. Ohne ihn
        // suchte der Hub sich einen aus, und bei vier Systemen waere das
        // drei Mal der falsche.
        $antwort = (self::BRUECKE)::gateway(
            FremdeMeldungen::CAPABILITY,
            ['limit' => $grenze],
            $slug,
            $adresse,
        );

        return Auspacken::antwort(is_array($antwort) ? $antwort : []);
    }
}
