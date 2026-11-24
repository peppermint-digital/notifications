<?php

namespace Peppermint\Notifications\Push;

use GuzzleHttp\Client;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use RuntimeException;

/**
 * Der eigentliche Web-Push-Versand im Betrieb `local`.
 *
 * Signiert mit dem eigenen VAPID-Paar aus `notifications.push`. Ohne beide
 * Teile wird nichts verschickt — ein Versand ohne Signatur nimmt kein
 * Push-Dienst an, und ihn trotzdem zu versuchen hiesse, jedes Abo als
 * „abgelaufen" zu missdeuten.
 *
 * Eine eigene Klasse, damit Tests und der E2E-Nachweis den Transport tauschen
 * koennen ({@see $client}), ohne den Weg dorthin zu aendern.
 */
class PushZusteller
{
    public function __construct(private readonly ?Client $client = null) {}

    public function eingerichtet(): bool
    {
        return filled(config('notifications.push.public_key')) && filled(config('notifications.push.private_key'));
    }

    /**
     * @param  iterable<PushSubscription>  $abos
     * @param  array<string, mixed>  $nutzlast
     * @return array<int, array{abo: PushSubscription, erfolg: bool, abgelaufen: bool, grund: string}>
     */
    public function senden(iterable $abos, array $nutzlast): array
    {
        if (! $this->eingerichtet()) {
            throw new RuntimeException('Kein eigenes VAPID-Paar eingerichtet (notifications.push.public_key / private_key).');
        }

        $versand = new WebPush(
            auth: ['VAPID' => [
                'subject' => (string) (config('notifications.push.subject') ?: config('app.url')),
                'publicKey' => (string) config('notifications.push.public_key'),
                'privateKey' => (string) config('notifications.push.private_key'),
            ]],
            defaultOptions: ['TTL' => 86400, 'urgency' => 'normal'],
            client: $this->client ?? new Client(['timeout' => 10]),
        );
        $versand->setReuseVAPIDHeaders(true);

        $json = json_encode($nutzlast, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $nachAdresse = [];

        foreach ($abos as $abo) {
            $nachAdresse[$abo->endpoint] = $abo;

            $versand->queueNotification(new Subscription(
                endpoint: $abo->endpoint,
                publicKey: $abo->public_key,
                authToken: $abo->auth_token,
                contentEncoding: 'aes128gcm',
            ), $json);
        }

        $ergebnisse = [];

        foreach ($versand->flush() as $bericht) {
            $abo = $nachAdresse[$bericht->getEndpoint()] ?? null;

            if ($abo === null) {
                continue;
            }

            $ergebnisse[] = [
                'abo' => $abo,
                'erfolg' => $bericht->isSuccess(),
                'abgelaufen' => $bericht->isSubscriptionExpired(),
                'grund' => $bericht->isSuccess() ? '' : mb_substr($bericht->getReason(), 0, 200),
            ];
        }

        return $ergebnisse;
    }
}
