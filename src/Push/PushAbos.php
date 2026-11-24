<?php

namespace Peppermint\Notifications\Push;

use Illuminate\Database\Eloquent\Model;

/**
 * Abos im eigenen Push-Betrieb speichern und zuruecknehmen.
 *
 * Die Adresse eines Abos gehoert einem Geraet, nicht einem Menschen. Meldet
 * sich auf demselben Browser jemand anderes an und abonniert, wandert die
 * Zeile zu ihm — sonst bekaeme die vorige Person weiter die Meldungen auf ein
 * Geraet, das sie nicht mehr benutzt.
 */
class PushAbos
{
    /**
     * @param  array{endpoint: string, keys: array{p256dh: string, auth: string}}  $abo
     */
    public function speichern(Model $user, array $abo, ?string $userAgent = null): PushSubscription
    {
        return PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => PushSubscription::fingerabdruck($abo['endpoint'])],
            [
                'user_id' => $user->getKey(),
                'endpoint' => $abo['endpoint'],
                'public_key' => $abo['keys']['p256dh'],
                'auth_token' => $abo['keys']['auth'],
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 512),
            ],
        );
    }

    /**
     * Nur das EIGENE Abo: Wer eine fremde Adresse kennt, kann sie nicht
     * abbestellen.
     */
    public function entfernen(Model $user, string $endpoint): int
    {
        return PushSubscription::query()
            ->fuer($user->getKey())
            ->where('endpoint_hash', PushSubscription::fingerabdruck($endpoint))
            ->delete();
    }
}
