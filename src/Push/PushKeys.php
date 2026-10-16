<?php

namespace Peppermint\Notifications\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Woher der oeffentliche Push-Schluessel kommt.
 *
 * ## Warum ein Schluessel fuer alle Systeme
 *
 * Ein Web-Push-Abo ist an den VAPID-PUBLIC-KEY gebunden, mit dem der Browser
 * abonniert hat (`applicationServerKey`) — nicht an die Domain. Holt jedes
 * Produkt denselben Schluessel, kann nur die Stelle signieren, die den
 * privaten Teil haelt. Genau das war der Wunsch: der Schluessel liegt in AI
 * Brain, nicht viermal verteilt.
 *
 * Der Service Worker bleibt pro Produkt — jede Herkunft braucht einen
 * eigenen. Der Schluessel ist einer.
 *
 * ## Was das fuer bestehende Abos heisst
 *
 * Sie gelten NICHT weiter. Ein Abo, das mit dem Schluessel des Manager
 * entstanden ist, laesst sich mit dem Schluessel des Brains nicht bedienen —
 * der Push-Dienst lehnt es ab. Jeder Mensch abonniert einmal neu. Das ist
 * der Grund, warum dieser Schritt zuletzt kommt: Er ist der einzige, der
 * eine LAUFENDE Zustellung anfasst.
 *
 * ## Im Einzelbetrieb
 *
 * Ohne Hub haelt das Produkt sein eigenes Paar, und hier kommt der lokale
 * Schluessel zurueck. Derselbe Code, andere Quelle.
 */
class PushKeys
{
    /** Wie lange der geholte Schluessel gilt. Er aendert sich praktisch nie. */
    private const HALTBAR = 3600;

    private const SPEICHER = 'notifications.push.public_key';

    /**
     * Der oeffentliche Schluessel, mit dem ein Browser abonnieren soll.
     *
     * `null` heisst: Es gibt keinen — dann darf die Oberflaeche gar nicht
     * erst anbieten zu abonnieren. Ein Abo ohne Schluessel gibt es nicht,
     * und ein Knopf, der nichts tut, ist schlimmer als keiner.
     */
    public function publicKey(): ?string
    {
        if (config('notifications.hub', 'local') !== 'brain') {
            return $this->lokal();
        }

        $geholt = Cache::remember(self::SPEICHER, self::HALTBAR, fn (): ?string => $this->vomHub());

        // Kein Schluessel vom Hub: lieber den eigenen als gar keinen. Wer
        // damit abonniert, bekommt seine Meldungen vom Produkt statt vom
        // Brain — weniger als gewollt, aber nicht nichts.
        return $geholt ?? $this->lokal();
    }

    /**
     * Den gespeicherten Schluessel vergessen — nach einem Wechsel am Hub.
     */
    public function vergessen(): void
    {
        Cache::forget(self::SPEICHER);
    }

    private function lokal(): ?string
    {
        $schluessel = config('notifications.push.public_key');

        return is_string($schluessel) && $schluessel !== '' ? $schluessel : null;
    }

    /**
     * Beim Hub nachfragen.
     *
     * Ohne Anmeldung: Ein oeffentlicher Schluessel ist oeffentlich — er geht
     * ohnehin an jeden Browser, der abonniert. Ihn hinter OAuth zu legen
     * hiesse, fuer ein Geheimnis zu sorgen, das keines ist, und dafuer eine
     * Abhaengigkeit mehr in einen Weg zu bauen, der schon genug davon hat.
     */
    private function vomHub(): ?string
    {
        $adresse = (string) config('notifications.push.hub_url', '');

        if ($adresse === '') {
            Log::warning('Push-Schluessel sollte vom Hub kommen, aber es ist keine Adresse eingestellt.');

            return null;
        }

        try {
            $antwort = Http::timeout(5)->acceptJson()->get($adresse);

            if (! $antwort->successful()) {
                Log::warning('Push-Schluessel vom Hub: unerwartete Antwort.', ['status' => $antwort->status()]);

                return null;
            }

            $schluessel = $antwort->json('public_key');

            return is_string($schluessel) && $schluessel !== '' ? $schluessel : null;
        } catch (\Throwable $fehler) {
            Log::warning('Push-Schluessel vom Hub nicht erreichbar.', ['error' => $fehler->getMessage()]);

            return null;
        }
    }
}
